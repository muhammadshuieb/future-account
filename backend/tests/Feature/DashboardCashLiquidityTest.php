<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashService;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardCashLiquidityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);
        app(CurrencyService::class)->ensureSeeded();
        app(CurrencyService::class)->seedDemoRates(now());

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_dashboard_cash_bank_match_cash_boxes_and_banks_apis(): void
    {
        $cash = app(CashService::class);

        $boxes = CashBox::query()->where('is_active', true)->get();
        $banks = Bank::query()->where('is_active', true)->get();

        $expectedByCurrency = [];
        foreach ($boxes as $box) {
            $code = strtoupper((string) ($box->currency ?: 'USD'));
            $expectedByCurrency[$code]['cash'] = ($expectedByCurrency[$code]['cash'] ?? 0)
                + $cash->cashBoxCurrencyBalance($box);
        }
        foreach ($banks as $bank) {
            $code = strtoupper((string) ($bank->currency ?: 'USD'));
            $expectedByCurrency[$code]['bank'] = ($expectedByCurrency[$code]['bank'] ?? 0)
                + $cash->bankCurrencyBalance($bank);
        }

        $summary = $cash->liquiditySummary();
        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
        $apiBoxes = collect($this->getJson('/api/cash-boxes')->assertOk()->json('data'));
        $apiBanks = collect($this->getJson('/api/banks')->assertOk()->json('data'));

        foreach ($boxes as $box) {
            $apiBalance = (float) $apiBoxes->firstWhere('id', $box->id)['balance'];
            $this->assertEqualsWithDelta(
                $cash->cashBoxCurrencyBalance($box),
                $apiBalance,
                0.01,
                'cash-box API balance mismatch for '.$box->code
            );
        }

        foreach ($banks as $bank) {
            $apiBalance = (float) $apiBanks->firstWhere('id', $bank->id)['balance'];
            $this->assertEqualsWithDelta(
                $cash->bankCurrencyBalance($bank),
                $apiBalance,
                0.01,
                'bank API balance mismatch for '.$bank->code
            );
        }

        $this->assertEqualsWithDelta($summary['cash'], (float) $dashboard['cash'], 0.01);
        $this->assertEqualsWithDelta($summary['bank'], (float) $dashboard['bank'], 0.01);
        $this->assertEqualsWithDelta($summary['liquidity'], (float) $dashboard['liquidity'], 0.01);
        $this->assertEqualsWithDelta(
            (float) $dashboard['cash'] + (float) $dashboard['bank'],
            (float) $dashboard['liquidity'],
            0.01
        );

        $dashBoxes = collect($dashboard['cash_boxes'] ?? []);
        $dashBanks = collect($dashboard['banks'] ?? []);
        $this->assertCount($boxes->count(), $dashBoxes);
        $this->assertCount($banks->count(), $dashBanks);
        foreach ($boxes as $box) {
            $row = $dashBoxes->firstWhere('id', $box->id);
            $this->assertNotNull($row, 'missing dashboard cash_boxes row for '.$box->code);
            $this->assertEqualsWithDelta(
                $cash->cashBoxCurrencyBalance($box),
                (float) $row['balance'],
                0.01,
                'dashboard cash box mismatch for '.$box->code
            );
        }
        foreach ($banks as $bank) {
            $row = $dashBanks->firstWhere('id', $bank->id);
            $this->assertNotNull($row, 'missing dashboard banks row for '.$bank->code);
            $this->assertEqualsWithDelta(
                $cash->bankCurrencyBalance($bank),
                (float) $row['balance'],
                0.01,
                'dashboard bank mismatch for '.$bank->code
            );
        }

        // Revenues must stay independent of cash/bank balances.
        $this->assertArrayHasKey('revenue', $dashboard);
        $this->assertNotEquals(
            round((float) $dashboard['revenue'], 2),
            round((float) $dashboard['cash'], 2),
            'dashboard cash must not equal revenue'
        );

        $byCurrency = collect($dashboard['by_currency'] ?? []);
        foreach ($expectedByCurrency as $code => $row) {
            $dashRow = $byCurrency->firstWhere('currency', $code);
            $this->assertNotNull($dashRow, 'missing by_currency row for '.$code);
            $this->assertEqualsWithDelta((float) ($row['cash'] ?? 0), (float) $dashRow['cash'], 0.01);
            $this->assertEqualsWithDelta((float) ($row['bank'] ?? 0), (float) $dashRow['bank'], 0.01);
            $this->assertEqualsWithDelta(
                (float) $dashRow['cash'] + (float) $dashRow['bank'],
                (float) $dashRow['liquidity'],
                0.01
            );
        }

        $usdOnly = $this->getJson('/api/dashboard/summary?currency=USD')->assertOk()->json('data');
        $usdSummary = $cash->liquiditySummary(null, 'USD');
        $this->assertEqualsWithDelta($usdSummary['cash'], (float) $usdOnly['cash'], 0.01);
        $this->assertEqualsWithDelta($usdSummary['bank'], (float) $usdOnly['bank'], 0.01);
        $this->assertNull($usdOnly['by_currency']);
        $this->assertNull($usdOnly['liquidity_by_currency']);
        foreach ($usdOnly['cash_boxes'] ?? [] as $row) {
            $this->assertSame('USD', strtoupper((string) $row['currency']));
        }
        foreach ($usdOnly['banks'] ?? [] as $row) {
            $this->assertSame('USD', strtoupper((string) $row['currency']));
        }
    }

    public function test_credit_sale_does_not_inflate_dashboard_cash(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $branchId = Branch::query()->where('code', 'DAM')->value('id');

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $before = $this->getJson('/api/dashboard/summary?branch_id='.$branchId)->assertOk()->json('data');

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 900, 'tax_rate' => 0]],
        ])->assertCreated();

        $after = $this->getJson('/api/dashboard/summary?branch_id='.$branchId)->assertOk()->json('data');

        $this->assertEqualsWithDelta((float) $before['cash'], (float) $after['cash'], 0.01);
        $this->assertEqualsWithDelta((float) $before['bank'], (float) $after['bank'], 0.01);
        $this->assertEqualsWithDelta((float) $before['liquidity'], (float) $after['liquidity'], 0.01);
        $this->assertGreaterThan((float) $before['receivables'], (float) $after['receivables']);
    }

    public function test_currencies_are_not_summed_as_one_native_total(): void
    {
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $usdBox = CashBox::query()->where('code', 'CASH-USD')->firstOrFail();

        $this->assertSame('SYP', strtoupper((string) $sypBox->currency));
        $this->assertSame('USD', strtoupper((string) $usdBox->currency));

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
        $byCurrency = collect($dashboard['by_currency'] ?? []);

        $sypRow = $byCurrency->firstWhere('currency', 'SYP');
        $usdRow = $byCurrency->firstWhere('currency', 'USD');
        $this->assertNotNull($sypRow);
        $this->assertNotNull($usdRow);

        $cash = app(CashService::class);
        $this->assertEqualsWithDelta(
            $cash->cashBoxCurrencyBalance($sypBox),
            (float) $sypRow['cash'],
            0.01
        );

        // Headline cash is base-equivalent (USD), not a raw SYP+USD sum.
        $rawNativeSum = (float) $sypRow['cash'] + (float) $usdRow['cash'];
        $this->assertGreaterThan(0, (float) $sypRow['cash'], 'expected SYP cash box balance');
        $this->assertNotEquals(
            round($rawNativeSum, 2),
            round((float) $dashboard['cash'], 2),
            'dashboard cash must not be a raw multi-currency sum'
        );
        // Demo rate: 1 USD = 15000 SYP → 10000 SYP ≈ 0.67 USD.
        $expectedSypInBase = round((float) $sypRow['cash'] / 15000, 2);
        $this->assertEqualsWithDelta(
            $expectedSypInBase + (float) $usdRow['cash'],
            (float) $dashboard['cash'],
            0.05
        );
    }
}
