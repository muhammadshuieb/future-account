<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\JournalDetail;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\SalesInvoice;
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

class SalesInvoiceMultiCurrencyPaymentTest extends TestCase
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
        app(CurrencyService::class)->seedDemoRates();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_posted_invoice_accepts_usd_and_syp_payment_splits(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $usdBox = CashBox::query()->where('code', 'CASH-USD')->firstOrFail();
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $this->assertSame('SYP', strtoupper((string) $sypBox->currency));

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 40, 'tax_rate' => 0]],
        ])->assertCreated();

        // Invoice 1000 USD. Pay 400 USD + 9,000,000 SYP at 1 SYP = 1/15000 USD → 600 USD.
        $sypRateToUsd = round(1 / 15000, 8);
        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'payment_type' => 'cash',
            'status' => 'posted',
            'payments' => [
                [
                    'amount' => 400,
                    'currency' => 'USD',
                    'exchange_rate' => 1,
                    'base_amount' => 400,
                    'cash_box_id' => $usdBox->id,
                ],
                [
                    'amount' => 9000000,
                    'currency' => 'SYP',
                    'exchange_rate' => $sypRateToUsd,
                    'base_amount' => 600,
                    'cash_box_id' => $sypBox->id,
                ],
            ],
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->assertSame('cash', $invoice['payment_type']);
        $this->assertEqualsWithDelta(1000, (float) $invoice['paid_amount'], 0.02);
        $this->assertIsArray($invoice['payment_splits'] ?? null);
        $this->assertCount(2, $invoice['payment_splits']);

        $inv = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertEqualsWithDelta(1000, (float) $inv->paid_amount, 0.02);

        $receipts = Receipt::query()->where('sales_invoice_id', $inv->id)->orderBy('id')->get();
        $this->assertCount(2, $receipts);
        $this->assertSame('USD', strtoupper((string) $receipts[0]->currency));
        $this->assertEqualsWithDelta(400, (float) $receipts[0]->amount, 0.01);
        $this->assertSame('SYP', strtoupper((string) $receipts[1]->currency));
        $this->assertEqualsWithDelta(9000000, (float) $receipts[1]->amount, 0.01);

        $cash = app(CashService::class);
        $this->assertGreaterThan(0, $cash->cashBoxCurrencyBalance($usdBox->fresh()));
        $this->assertGreaterThan(0, $cash->cashBoxCurrencyBalance($sypBox->fresh()));
    }

    public function test_collect_remaining_in_syp_against_usd_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'USD',
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $sypRateToUsd = round(1 / 15000, 8);
        // 200 USD = 3,000,000 SYP at demo rate
        $res = $this->postJson("/api/sales-invoices/{$invoice['id']}/collect", [
            'amount' => 3000000,
            'currency' => 'SYP',
            'exchange_rate' => $sypRateToUsd,
            'base_amount' => 200,
            'cash_box_id' => $sypBox->id,
            'method' => 'cash',
        ])->assertCreated()->json('data');

        $this->assertSame('SYP', strtoupper((string) $res['receipt']['currency']));
        $this->assertEqualsWithDelta(200, (float) $res['invoice']['paid_amount'], 0.02);

        $inv = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertEqualsWithDelta(200, (float) $inv->paid_amount, 0.02);
    }

    public function test_collect_rejects_syp_overpay_converted_to_invoice_currency(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'USD',
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $sypRateToUsd = round(1 / 15000, 8);
        // 3,000,000 SYP ≈ 200 USD > remaining 100
        $this->postJson("/api/sales-invoices/{$invoice['id']}/collect", [
            'amount' => 3000000,
            'currency' => 'SYP',
            'exchange_rate' => $sypRateToUsd,
            'base_amount' => 200,
            'cash_box_id' => $sypBox->id,
        ])->assertStatus(422);
    }

    public function test_collect_posts_fx_loss_when_payment_rate_weaker_than_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'SYP',
            'exchange_rate' => round(1 / 15000, 8),
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100000, 'tax_rate' => 0]],
        ])->assertCreated();

        // Invoice 1,500,000 SYP booked at 1/15000 → AR base 100
        $invoiceRate = round(1 / 15000, 8);
        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'SYP',
            'exchange_rate' => $invoiceRate,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1500000, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(100, (float) $invoice['base_amount'], 0.05);

        // Collect full amount at weaker rate 1/20000 → cash base 75 → FX loss 25
        $payRate = round(1 / 20000, 8);
        $res = $this->postJson("/api/sales-invoices/{$invoice['id']}/collect", [
            'amount' => 1500000,
            'currency' => 'SYP',
            'exchange_rate' => $payRate,
            'base_amount' => 75,
            'cash_box_id' => $sypBox->id,
            'method' => 'cash',
        ])->assertCreated()->json('data');

        $receipt = Receipt::query()->findOrFail($res['receipt']['id']);
        $this->assertNotNull($receipt->journal_entry_id);

        $lossId = Account::query()->where('code', '5105')->value('id');
        $lossDebit = (float) JournalDetail::query()
            ->where('journal_entry_id', $receipt->journal_entry_id)
            ->where('account_id', $lossId)
            ->sum('debit');
        $this->assertEqualsWithDelta(25, $lossDebit, 0.05);

        $inv = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertEqualsWithDelta(1500000, (float) $inv->paid_amount, 0.01);
    }

    public function test_customer_statement_shows_syp_receipt_document_amount(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $usdBox = CashBox::query()->where('code', 'CASH-USD')->firstOrFail();
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 40, 'tax_rate' => 0]],
        ])->assertCreated();

        $sypRateToUsd = round(1 / 15000, 8);
        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'USD',
            'payment_type' => 'cash',
            'status' => 'posted',
            'payments' => [
                [
                    'amount' => 100,
                    'currency' => 'USD',
                    'exchange_rate' => 1,
                    'base_amount' => 100,
                    'cash_box_id' => $usdBox->id,
                ],
                [
                    'amount' => 1500000,
                    'currency' => 'SYP',
                    'exchange_rate' => $sypRateToUsd,
                    'base_amount' => 100,
                    'cash_box_id' => $sypBox->id,
                ],
            ],
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $statement = $this->getJson("/api/customers/{$customer->id}/statement")->assertOk()->json('data');
        $sypRow = collect($statement['rows'])->first(
            fn ($r) => ($r['type'] ?? '') === 'receipt' && strtoupper((string) ($r['currency'] ?? '')) === 'SYP'
        );
        $this->assertNotNull($sypRow, 'SYP receipt must appear on customer statement');
        $this->assertEqualsWithDelta(1500000, (float) $sypRow['document_amount'], 0.01);
        $this->assertEqualsWithDelta(100, (float) $sypRow['credit'], 0.05);
        $this->assertStringContainsString('SYP', (string) ($sypRow['document_label'] ?? ''));
        $this->assertStringContainsString($invoice['invoice_number'], (string) ($sypRow['document_label'] ?? ''));

        $invRow = collect($statement['rows'])->first(
            fn ($r) => ($r['type'] ?? '') === 'invoice' && ($r['document_id'] ?? null) == $invoice['id']
        );
        $this->assertNotNull($invRow);
        $this->assertGreaterThanOrEqual(2, count($invRow['invoice']['payments'] ?? []));
    }
}
