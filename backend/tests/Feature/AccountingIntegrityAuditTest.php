<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\JournalDetail;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regressions found while auditing the live system: FX rounding, sub-ledger vs GL
 * agreement on returns and counts, and base-currency reporting.
 */
class AccountingIntegrityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);
        app(CurrencyService::class)->seedDemoRates();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $this->customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $this->supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $this->product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
    }

    public function test_return_list_endpoints_load_and_search(): void
    {
        $this->buyStock(10, 100);

        $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 100],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 200],
            ],
        ])->assertCreated();

        $this->getJson('/api/purchase-returns')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/sales-returns')->assertOk()->assertJsonCount(1, 'data');

        // `notes` is listed as searchable but the returns tables have no such column.
        $this->getJson('/api/purchase-returns?q=PR')->assertOk();
        $this->getJson('/api/sales-returns?q=SR')->assertOk();
    }

    public function test_sales_return_reverses_inventory_and_cogs(): void
    {
        $this->buyStock(10, 100);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 200, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $return = $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 200],
            ],
        ]);

        $return->assertCreated()->assertJsonPath('data.status', 'posted');

        $this->assertEqualsWithDelta(8, $this->stockQty(), 0.001);

        // GL inventory: 1000 purchased − 400 sold + 200 returned = 800, matching 8 × 100.
        $this->assertEqualsWithDelta(800, $this->accountBalance('1104'), 0.01);
        $this->assertEqualsWithDelta(200, $this->accountBalance('5101'), 0.01);

        $entry = JournalEntry::query()->findOrFail(SalesReturn::query()->value('journal_entry_id'));
        $this->assertTrue($entry->isBalanced());
    }

    public function test_purchase_return_keeps_inventory_ledger_and_stock_aligned(): void
    {
        $this->buyStock(10, 100);

        $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 3, 'unit_cost' => 100],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'posted');

        $this->assertEqualsWithDelta(7, $this->stockQty(), 0.001);
        $this->assertEqualsWithDelta(700, $this->accountBalance('1104'), 0.01);

        $entry = JournalEntry::query()->findOrFail(PurchaseReturn::query()->value('journal_entry_id'));
        $this->assertTrue($entry->isBalanced());
    }

    public function test_foreign_currency_invoices_always_post_balanced_entries(): void
    {
        $this->buyStock(200, 1);

        // Sub-cent FX rounding used to make subtotal + tax drift away from base_amount.
        foreach ([1234567, 999999, 7777777, 1000001, 3141593] as $index => $unitPrice) {
            $response = $this->postJson('/api/sales-invoices', [
                'invoice_date' => now()->toDateString(),
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'currency' => 'SYP',
                'exchange_rate' => 0.00006667,
                'payment_type' => 'credit',
                'status' => 'posted',
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $unitPrice, 'tax_rate' => 11],
                ],
            ]);

            $response->assertCreated()->assertJsonPath('data.status', 'posted');

            $entry = JournalEntry::query()->findOrFail($response->json('data.journal_entry_id'));
            $this->assertTrue(
                $entry->isBalanced(),
                "sales invoice #{$index} at unit price {$unitPrice} produced an unbalanced entry"
            );
        }

        foreach ([1234567, 999999, 7777777] as $index => $unitCost) {
            $response = $this->postJson('/api/purchase-invoices', [
                'invoice_date' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'currency' => 'SYP',
                'exchange_rate' => 0.00006667,
                'payment_type' => 'credit',
                'status' => 'posted',
                'lines' => [
                    ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => $unitCost, 'tax_rate' => 11],
                ],
            ]);

            $response->assertCreated()->assertJsonPath('data.status', 'posted');

            $entry = JournalEntry::query()->findOrFail($response->json('data.journal_entry_id'));
            $this->assertTrue(
                $entry->isBalanced(),
                "purchase invoice #{$index} at unit cost {$unitCost} produced an unbalanced entry"
            );
        }
    }

    public function test_credit_limit_compares_base_currency_amounts(): void
    {
        $this->buyStock(10, 1);
        $this->customer->update(['credit_limit' => 500]);

        // 1.5M SYP is 100 USD, well inside a 500 USD limit.
        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency' => 'SYP',
            'exchange_rate' => 0.00006667,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1_500_000, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        // 9M SYP is 600 USD, over the limit.
        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency' => 'SYP',
            'exchange_rate' => 0.00006667,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 9_000_000, 'tax_rate' => 0],
            ],
        ])->assertStatus(422);
    }

    public function test_foreign_currency_purchase_stores_cost_price_in_base_currency(): void
    {
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'currency' => 'TRY',
            'exchange_rate' => 0.03,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_cost' => 1000, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        // 1000 TRY at 0.03 = 30 USD, not 1000.
        $this->assertEqualsWithDelta(30, (float) $this->product->fresh()->cost_price, 0.01);
        $this->assertEqualsWithDelta(150, $this->accountBalance('1104'), 0.01);
    }

    public function test_customer_statement_is_reported_in_base_currency_and_includes_returns(): void
    {
        $this->buyStock(20, 10);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 500, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency' => 'SYP',
            'exchange_rate' => 0.00006667,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 1_500_000, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 500],
            ],
        ])->assertCreated();

        $statement = $this->getJson("/api/customers/{$this->customer->id}/statement")->assertOk()->json('data');

        $this->assertSame('USD', $statement['currency']);
        $this->assertSame(['invoice', 'invoice', 'return'], collect($statement['rows'])->pluck('type')->sort()->values()->all());

        // 1000 USD + 100.01 USD (1.5M SYP) − 500 USD return.
        $this->assertEqualsWithDelta(600.01, (float) $statement['closing_balance'], 0.02);
        $this->assertEqualsWithDelta($this->accountBalance('1103'), (float) $statement['closing_balance'], 0.02);
    }

    public function test_supplier_statement_is_reported_in_base_currency_and_includes_returns(): void
    {
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 100, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'currency' => 'TRY',
            'exchange_rate' => 0.03,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 1000, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 100],
            ],
        ])->assertCreated();

        $statement = $this->getJson("/api/suppliers/{$this->supplier->id}/statement")->assertOk()->json('data');

        $this->assertSame('USD', $statement['currency']);

        // 1000 USD + 300 USD (10 000 TRY) − 200 USD return, shown as a positive liability.
        $this->assertEqualsWithDelta(1100, (float) $statement['closing_balance'], 0.02);
        $this->assertEqualsWithDelta(1100, $this->accountBalance('2101'), 0.02);
    }

    public function test_tax_report_converts_document_tax_to_base_currency(): void
    {
        Setting::setValue('tax_enabled', '1', 'finance', 'boolean', 'تفعيل الضريبة');
        Setting::setValue('tax_rate', '10', 'finance', 'string', 'نسبة الضريبة');

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'currency' => 'TRY',
            'exchange_rate' => 0.03,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 1000, 'tax_rate' => 10],
            ],
        ])->assertCreated();

        $report = $this->getJson('/api/reports/tax')->assertOk()->json('data');

        // 1000 TRY input VAT is 30 USD, not 1000.
        $this->assertSame('USD', $report['currency']);
        $this->assertEqualsWithDelta(30, (float) $report['input_vat'], 0.02);
    }

    public function test_cash_flow_report_covers_dedicated_cash_box_accounts(): void
    {
        $dedicated = Account::query()->create([
            'code' => '110109',
            'name' => 'صندوق التدقيق',
            'type' => 'asset',
            'nature' => 'debit',
            'parent_id' => Account::query()->where('code', '1101')->value('id'),
            'is_group' => false,
            'is_active' => true,
        ]);

        $box = CashBox::query()->create([
            'code' => 'CASH-AUDIT',
            'name' => 'صندوق التدقيق',
            'branch_id' => $this->warehouse->branch_id,
            'account_id' => $dedicated->id,
            'opening_balance' => 0,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->buyStock(10, 100);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_box_id' => $box->id,
            'payment_type' => 'cash',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 300, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $report = $this->getJson('/api/reports/cash-flow')->assertOk()->json('data');

        $this->assertEqualsWithDelta(600, (float) $report['total_inflow'], 0.01);
    }

    public function test_blocks_cross_currency_cash_transfer(): void
    {
        $sypBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $usdBox = CashBox::query()->where('code', 'CASH-USD')->firstOrFail();

        $this->postJson('/api/cash-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_type' => 'cash_box',
            'from_id' => $sypBox->id,
            'to_type' => 'cash_box',
            'to_id' => $usdBox->id,
            'amount' => 1000,
            'status' => 'posted',
        ])->assertStatus(422);

        $this->assertDatabaseCount('cash_transfers', 0);
    }

    public function test_inventory_count_posts_the_inventory_adjustment_to_the_ledger(): void
    {
        $this->buyStock(10, 100);

        $count = $this->postJson('/api/inventory-counts', [
            'warehouse_id' => $this->warehouse->id,
            'count_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $this->product->id, 'counted_qty' => 12],
            ],
        ]);

        $count->assertCreated();

        $this->postJson("/api/inventory-counts/{$count->json('data.id')}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertEqualsWithDelta(12, $this->stockQty(), 0.001);
        // Surplus of 2 × 100 must land in the inventory account, not only in stock levels.
        $this->assertEqualsWithDelta(1200, $this->accountBalance('1104'), 0.01);
        $this->assertEqualsWithDelta(200, $this->accountBalance('4102'), 0.01);
    }

    public function test_inventory_count_shortage_hits_expense_and_inventory(): void
    {
        $this->buyStock(10, 100);

        $count = $this->postJson('/api/inventory-counts', [
            'warehouse_id' => $this->warehouse->id,
            'count_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $this->product->id, 'counted_qty' => 7],
            ],
        ]);

        $count->assertCreated();
        $this->postJson("/api/inventory-counts/{$count->json('data.id')}/post")->assertOk();

        $this->assertEqualsWithDelta(7, $this->stockQty(), 0.001);
        $this->assertEqualsWithDelta(700, $this->accountBalance('1104'), 0.01);
        $this->assertEqualsWithDelta(300, $this->accountBalance('5104'), 0.01);
    }

    public function test_dashboard_receivables_and_payables_are_net_of_returns(): void
    {
        $this->buyStock(20, 100);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 300, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 300],
            ],
        ])->assertCreated();

        $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_cost' => 100],
            ],
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');

        $this->assertEqualsWithDelta(1200, (float) $dashboard['receivables'], 0.01);
        $this->assertEqualsWithDelta($this->accountBalance('1103'), (float) $dashboard['receivables'], 0.01);

        $this->assertEqualsWithDelta(1800, (float) $dashboard['payables'], 0.01);
        $this->assertEqualsWithDelta($this->accountBalance('2101'), (float) $dashboard['payables'], 0.01);
    }

    public function test_dashboard_nets_unallocated_receipts_and_supplier_payments(): void
    {
        $this->buyStock(20, 100);
        $cashBoxId = \App\Models\CashBox::query()->where('code', 'CASH-USD')->value('id')
            ?? \App\Models\CashBox::query()->value('id');

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 4, 'unit_price' => 250, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/receipts', [
            'receipt_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'cash_box_id' => $cashBoxId,
            'method' => 'cash',
            'amount' => 150,
            'currency' => 'USD',
            'status' => 'posted',
        ])->assertCreated();

        $this->postJson('/api/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'cash_box_id' => $cashBoxId,
            'method' => 'cash',
            'amount' => 200,
            'currency' => 'USD',
            'status' => 'posted',
        ])->assertCreated();

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');

        $this->assertEqualsWithDelta(850, (float) $dashboard['receivables'], 0.01);
        $this->assertEqualsWithDelta($this->accountBalance('1103'), (float) $dashboard['receivables'], 0.01);
        $this->assertEqualsWithDelta(1800, (float) $dashboard['payables'], 0.01);
        $this->assertEqualsWithDelta($this->accountBalance('2101'), (float) $dashboard['payables'], 0.01);
    }

    public function test_trial_balance_stays_balanced_after_the_full_flow(): void
    {
        $this->buyStock(20, 100);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 6, 'unit_price' => 250, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 250],
            ],
        ])->assertCreated();

        $trial = $this->getJson('/api/reports/trial-balance')->assertOk()->json('data');
        $this->assertEqualsWithDelta((float) $trial['total_debit'], (float) $trial['total_credit'], 0.01);

        // Inventory sub-ledger value must equal the inventory control account.
        $stockValue = round((float) StockLevel::query()->where('quantity', '>', 0)->get()
            ->sum(fn (StockLevel $l) => (float) $l->quantity * (float) Product::query()->whereKey($l->product_id)->value('cost_price')), 2);
        $this->assertEqualsWithDelta($stockValue, $this->accountBalance('1104'), 0.01);

        $inventory = $this->getJson('/api/reports/inventory')->assertOk()->json('data');
        $this->assertEqualsWithDelta($stockValue, (float) $inventory['total_value'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $inventory['variance'], 0.01);
    }

    public function test_stock_is_valued_at_moving_average_and_ties_to_the_ledger(): void
    {
        // Two receipts at different costs, then a sale, an adjustment and a return.
        $this->buyStock(50, 100);
        $this->buyStock(30, 110);

        // (50 × 100 + 30 × 110) / 80 = 103.75
        $this->assertEqualsWithDelta(103.75, (float) $this->product->fresh()->cost_price, 0.01);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 200, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 200],
            ],
        ])->assertCreated();

        $inventory = $this->getJson('/api/reports/inventory')->assertOk()->json('data');

        $this->assertEqualsWithDelta(72, $this->stockQty(), 0.001);
        $this->assertEqualsWithDelta(72 * 103.75, (float) $inventory['total_value'], 0.01);
        $this->assertEqualsWithDelta((float) $inventory['total_value'], (float) $inventory['gl_value'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $inventory['variance'], 0.01);
    }

    protected function buyStock(float $qty, float $unitCost): void
    {
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => $qty, 'unit_cost' => $unitCost, 'tax_rate' => 0],
            ],
        ])->assertCreated();
    }

    protected function stockQty(): float
    {
        return (float) StockLevel::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }

    /** Signed balance in the account's natural direction. */
    protected function accountBalance(string $code): float
    {
        $account = Account::query()->where('code', $code)->firstOrFail();

        $agg = JournalDetail::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
            ->first();

        $debit = (float) ($agg->debit ?? 0);
        $credit = (float) ($agg->credit ?? 0);

        return round($account->nature === 'debit' ? $debit - $credit : $credit - $debit, 2);
    }
}
