<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListSearchAndBranchReportTest extends TestCase
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

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_customer_search_filters_by_q_param(): void
    {
        $all = $this->getJson('/api/customers');
        $all->assertOk();
        $this->assertGreaterThan(1, count($all->json('data')));

        $filtered = $this->getJson('/api/customers?q=CUS-001');
        $filtered->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('CUS-001', $filtered->json('data.0.code'));
    }

    public function test_sales_invoice_search_filters_by_number_or_partner(): void
    {
        $dam = Branch::query()->where('code', 'DAM')->firstOrFail();
        $wh = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $userId = User::query()->first()->id;

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-SEARCH-UNIQUE-999',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $wh->id,
            'branch_id' => $dam->id,
            'status' => 'draft',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 50,
            'subtotal' => 50,
            'tax_amount' => 0,
            'total' => 50,
            'paid_amount' => 0,
            'created_by' => $userId,
        ]);

        $hit = $this->getJson('/api/sales-invoices?q=SI-SEARCH-UNIQUE');
        $hit->assertOk();
        $this->assertTrue(collect($hit->json('data'))->contains(fn ($r) => $r['invoice_number'] === 'SI-SEARCH-UNIQUE-999'));

        $miss = $this->getJson('/api/sales-invoices?q=NO-SUCH-INVOICE-XYZ');
        $miss->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sales_report_branch_filter_changes_totals(): void
    {
        $dam = Branch::query()->where('code', 'DAM')->firstOrFail();
        $alp = Branch::query()->where('code', 'ALP')->firstOrFail();
        $whDam = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whAlp = Warehouse::query()->where('branch_id', $alp->id)->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $userId = User::query()->first()->id;
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-RPT-DAM',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $whDam->id,
            'branch_id' => $dam->id,
            'status' => 'posted',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 1000,
            'subtotal' => 1000,
            'tax_amount' => 0,
            'total' => 1000,
            'paid_amount' => 0,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-RPT-ALP',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $whAlp->id,
            'branch_id' => $alp->id,
            'status' => 'posted',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 2500,
            'subtotal' => 2500,
            'tax_amount' => 0,
            'total' => 2500,
            'paid_amount' => 0,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        $all = $this->getJson("/api/reports/sales?from={$from}&to={$to}");
        $all->assertOk();
        $allTotal = (float) $all->json('data.total');
        $this->assertGreaterThanOrEqual(3500.0, $allTotal);

        $byDam = $this->getJson("/api/reports/sales?from={$from}&to={$to}&branch_id={$dam->id}");
        $byDam->assertOk()->assertJsonPath('data.branch_id', $dam->id);
        $this->assertEqualsWithDelta(1000.0, (float) $byDam->json('data.total'), 0.01);

        $byAlp = $this->getJson("/api/reports/sales?from={$from}&to={$to}&branch_id={$alp->id}");
        $byAlp->assertOk();
        $this->assertEqualsWithDelta(2500.0, (float) $byAlp->json('data.total'), 0.01);
    }

    public function test_branch_complete_report_requires_branch_and_returns_summary(): void
    {
        $dam = Branch::query()->where('code', 'DAM')->firstOrFail();
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $missing = $this->getJson("/api/reports/branch-complete?from={$from}&to={$to}");
        $missing->assertStatus(422);

        $ok = $this->getJson("/api/reports/branch-complete?branch_id={$dam->id}&from={$from}&to={$to}");
        $ok->assertOk()
            ->assertJsonPath('data.branch.code', 'DAM')
            ->assertJsonStructure([
                'data' => [
                    'sales' => ['count', 'total'],
                    'purchases' => ['count', 'total'],
                    'profit' => ['gross_profit'],
                    'receivables',
                    'payables',
                    'stock_value',
                ],
            ]);
    }

    public function test_sales_invoice_unsettled_filter_returns_only_open_balances(): void
    {
        $dam = Branch::query()->where('code', 'DAM')->firstOrFail();
        $wh = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $userId = User::query()->first()->id;

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-UNSETTLED-OPEN',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $wh->id,
            'branch_id' => $dam->id,
            'status' => 'posted',
            'payment_type' => 'credit',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 500,
            'subtotal' => 500,
            'tax_amount' => 0,
            'total' => 500,
            'paid_amount' => 100,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-UNSETTLED-PAID',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $wh->id,
            'branch_id' => $dam->id,
            'status' => 'posted',
            'payment_type' => 'cash',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 200,
            'subtotal' => 200,
            'tax_amount' => 0,
            'total' => 200,
            'paid_amount' => 200,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        SalesInvoice::query()->create([
            'invoice_number' => 'SI-UNSETTLED-DRAFT',
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $wh->id,
            'branch_id' => $dam->id,
            'status' => 'draft',
            'payment_type' => 'credit',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 300,
            'subtotal' => 300,
            'tax_amount' => 0,
            'total' => 300,
            'paid_amount' => 0,
            'created_by' => $userId,
        ]);

        $filtered = $this->getJson('/api/sales-invoices?unsettled=1');
        $filtered->assertOk();
        $numbers = collect($filtered->json('data'))->pluck('invoice_number');
        $this->assertTrue($numbers->contains('SI-UNSETTLED-OPEN'));
        $this->assertFalse($numbers->contains('SI-UNSETTLED-PAID'));
        $this->assertFalse($numbers->contains('SI-UNSETTLED-DRAFT'));

        $byStatus = $this->getJson('/api/sales-invoices?payment_status=open');
        $byStatus->assertOk();
        $this->assertTrue(collect($byStatus->json('data'))->contains(fn ($r) => $r['invoice_number'] === 'SI-UNSETTLED-OPEN'));
    }

    public function test_purchase_invoice_unsettled_filter_returns_only_open_balances(): void
    {
        $wh = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = \App\Models\Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $userId = User::query()->first()->id;

        \App\Models\PurchaseInvoice::query()->create([
            'invoice_number' => 'PI-UNSETTLED-OPEN',
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'status' => 'posted',
            'payment_type' => 'credit',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 800,
            'subtotal' => 800,
            'tax_amount' => 0,
            'total' => 800,
            'paid_amount' => 250,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        \App\Models\PurchaseInvoice::query()->create([
            'invoice_number' => 'PI-UNSETTLED-PAID',
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $wh->id,
            'status' => 'posted',
            'payment_type' => 'cash',
            'currency' => 'SYP',
            'exchange_rate' => 1,
            'base_amount' => 150,
            'subtotal' => 150,
            'tax_amount' => 0,
            'total' => 150,
            'paid_amount' => 150,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);

        $filtered = $this->getJson('/api/purchase-invoices?unsettled=1&sort=remaining_desc');
        $filtered->assertOk();
        $numbers = collect($filtered->json('data'))->pluck('invoice_number');
        $this->assertTrue($numbers->contains('PI-UNSETTLED-OPEN'));
        $this->assertFalse($numbers->contains('PI-UNSETTLED-PAID'));

        $open = collect($filtered->json('data'))->firstWhere('invoice_number', 'PI-UNSETTLED-OPEN');
        $this->assertNotNull($open);
        $this->assertEqualsWithDelta(550, (float) $open['total'] - (float) $open['paid_amount'], 0.01);
    }

    /**
     * Several list endpoints declare searchable columns that do not exist on their table
     * (for example `notes` on the returns tables), which used to raise a 500 on search.
     */
    public function test_every_searchable_list_endpoint_survives_a_search_term(): void
    {
        $endpoints = [
            'accounts', 'journal-entries', 'products', 'customers', 'suppliers', 'warehouses',
            'categories', 'units', 'sales-quotes', 'sales-orders', 'sales-invoices', 'sales-returns',
            'receipts', 'purchase-requests', 'purchase-orders', 'purchase-invoices', 'purchase-returns',
            'supplier-payments', 'cash-boxes', 'banks', 'cash-transfers', 'currency-exchanges',
            'bank-reconciliations', 'stock-levels', 'stock-movements', 'warehouse-transfers',
            'inventory-counts', 'employees', 'attendances', 'leave-requests', 'salary-records',
            'companies', 'branches', 'audit-logs', 'users',
        ];

        foreach ($endpoints as $endpoint) {
            $this->getJson("/api/{$endpoint}?q=a")->assertOk();
        }
    }

    public function test_product_search_accepts_q_alias(): void
    {
        $product = Product::query()->orderBy('id')->first();
        $this->assertNotNull($product);

        $byQ = $this->getJson('/api/products?q='.urlencode($product->sku));
        $byQ->assertOk();
        $this->assertTrue(collect($byQ->json('data'))->contains(fn ($r) => $r['sku'] === $product->sku));
    }
}
