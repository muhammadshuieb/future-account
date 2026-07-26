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

    public function test_product_search_accepts_q_alias(): void
    {
        $product = Product::query()->orderBy('id')->first();
        $this->assertNotNull($product);

        $byQ = $this->getJson('/api/products?q='.urlencode($product->sku));
        $byQ->assertOk();
        $this->assertTrue(collect($byQ->json('data'))->contains(fn ($r) => $r['sku'] === $product->sku));
    }
}
