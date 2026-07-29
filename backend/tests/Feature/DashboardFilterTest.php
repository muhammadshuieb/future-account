<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-28 10:00:00');

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);

        app(CurrencyService::class)->seedDemoRates(Carbon::parse('2026-07-25'));
        app(CurrencyService::class)->seedDemoRates(Carbon::parse('2026-07-28'));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_filters_change_month_sales_totals(): void
    {
        $dam = Branch::query()->where('code', 'DAM')->firstOrFail();
        $alp = Branch::query()->where('code', 'ALP')->firstOrFail();
        $whDam = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whAlp = Warehouse::query()->where('branch_id', $alp->id)->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();
        $userId = User::query()->first()->id;

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-DASH-DAM-SYP',
            'invoice_date' => '2026-07-25',
            'customer_id' => $customer->id,
            'warehouse_id' => $whDam->id,
            'branch_id' => $dam->id,
            'status' => 'posted',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 15000,
            'subtotal' => 15000,
            'tax_amount' => 0,
            'total' => 15000,
            'paid_amount' => 7500,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-DASH-ALP-USD',
            'invoice_date' => '2026-07-25',
            'customer_id' => $customer->id,
            'warehouse_id' => $whAlp->id,
            'branch_id' => $alp->id,
            'status' => 'posted',
            'currency' => 'USD',
            'exchange_rate' => 15000,
            'base_amount' => 30000,
            'subtotal' => 2,
            'tax_amount' => 0,
            'total' => 2,
            'paid_amount' => 1,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        PurchaseInvoice::query()->create([
            'invoice_number' => 'PI-DASH-DAM-TRY',
            'invoice_date' => '2026-07-25',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whDam->id,
            'branch_id' => $dam->id,
            'status' => 'posted',
            'currency' => 'TRY',
            'exchange_rate' => 450,
            'base_amount' => 20250,
            'subtotal' => 45,
            'tax_amount' => 0,
            'total' => 45,
            'paid_amount' => 15,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        PurchaseInvoice::query()->create([
            'invoice_number' => 'PI-DASH-ALP-USD',
            'invoice_date' => '2026-07-28',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whAlp->id,
            'branch_id' => $alp->id,
            'status' => 'posted',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'base_amount' => 3,
            'subtotal' => 3,
            'tax_amount' => 0,
            'total' => 3,
            'paid_amount' => 1,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        $all = $this->getJson('/api/dashboard/summary?days=7');
        $all->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.filter_branch_id', null)
            ->assertJsonPath('data.filter_currency', null)
            ->assertJsonPath('data.base_totals.currency', 'USD');

        $allMonthSales = (float) $all->json('data.month_sales');
        $this->assertEqualsWithDelta(3.0, $allMonthSales, 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $all->json('data.base_totals.month_sales'), 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $all->json('data.revenue'), 0.01);
        $this->assertEqualsWithDelta(4.35, (float) $all->json('data.expense'), 0.01);
        $this->assertEqualsWithDelta(-1.35, (float) $all->json('data.net_income'), 0.01);
        $this->assertEqualsWithDelta(1.5, (float) $all->json('data.receivables'), 0.01);
        $this->assertEqualsWithDelta(2.9, (float) $all->json('data.payables'), 0.01);
        $this->assertEqualsWithDelta(4.35, (float) $all->json('data.month_purchases'), 0.01);

        $byCurrency = collect($all->json('data.by_currency'));
        $this->assertNotEmpty($byCurrency);
        $sypRow = $byCurrency->firstWhere('currency', 'SYP');
        $tryRow = $byCurrency->firstWhere('currency', 'TRY');
        $usdRow = $byCurrency->firstWhere('currency', 'USD');
        $this->assertNotNull($sypRow);
        $this->assertNotNull($tryRow);
        $this->assertNotNull($usdRow);
        $this->assertEqualsWithDelta(15000.0, (float) $sypRow['month_sales'], 0.01);
        $this->assertEqualsWithDelta(7500.0, (float) $sypRow['receivables'], 0.01);
        $this->assertEqualsWithDelta(45.0, (float) $tryRow['month_purchases'], 0.01);
        $this->assertEqualsWithDelta(30.0, (float) $tryRow['payables'], 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $usdRow['month_sales'], 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $usdRow['month_purchases'], 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $usdRow['revenue'], 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $usdRow['receivables'], 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $usdRow['payables'], 0.01);

        $salesToday = collect($all->json('data.daily_sales'))->firstWhere('date', '2026-07-25');
        $purchasesToday = collect($all->json('data.daily_purchases'))->firstWhere('date', '2026-07-25');
        $this->assertNotNull($salesToday);
        $this->assertNotNull($purchasesToday);
        $this->assertEqualsWithDelta(3.0, (float) $salesToday['total'], 0.01);
        $this->assertEqualsWithDelta(1.35, (float) $purchasesToday['total'], 0.01);

        $byDam = $this->getJson('/api/dashboard/summary?days=7&branch_id='.$dam->id);
        $byDam->assertOk()->assertJsonPath('data.filter_branch_id', $dam->id);
        $this->assertEqualsWithDelta(1.0, (float) $byDam->json('data.month_sales'), 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $byDam->json('data.revenue'), 0.01);
        $this->assertEqualsWithDelta(1.35, (float) $byDam->json('data.month_purchases'), 0.01);
        $this->assertEqualsWithDelta(0.9, (float) $byDam->json('data.payables'), 0.01);

        $byAlp = $this->getJson('/api/dashboard/summary?days=7&branch_id='.$alp->id);
        $byAlp->assertOk();
        $this->assertEqualsWithDelta(2.0, (float) $byAlp->json('data.month_sales'), 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $byAlp->json('data.month_purchases'), 0.01);

        $byUsd = $this->getJson('/api/dashboard/summary?days=7&currency=USD');
        $byUsd->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.filter_currency', 'USD')
            ->assertJsonPath('data.by_currency', null)
            ->assertJsonPath('data.base_totals', null);
        $this->assertEqualsWithDelta(2.0, (float) $byUsd->json('data.month_sales'), 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $byUsd->json('data.revenue'), 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $byUsd->json('data.receivables'), 0.01);
        $this->assertEqualsWithDelta(3.0, (float) $byUsd->json('data.month_purchases'), 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $byUsd->json('data.payables'), 0.01);

        $byDamUsd = $this->getJson('/api/dashboard/summary?days=7&branch_id='.$dam->id.'&currency=USD');
        $byDamUsd->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $byDamUsd->json('data.month_sales'), 0.01);
    }
}
