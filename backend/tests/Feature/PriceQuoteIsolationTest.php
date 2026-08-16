<?php

namespace Tests\Feature;

use App\Models\CashBox;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PriceQuoteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class, ErpDemoSeeder::class]);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_creating_quote_does_not_affect_stock_cash_sales_or_gl(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 8, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        $stockBefore = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');
        $movementsBefore = StockMovement::query()->count();
        $salesInvoicesBefore = SalesInvoice::query()->count();
        $salesOrdersBefore = SalesOrder::query()->count();
        $purchaseInvoicesBefore = PurchaseInvoice::query()->count();
        $journalsBefore = JournalEntry::query()->count();
        $cashBoxesBefore = CashBox::query()->get(['id', 'opening_balance'])->mapWithKeys(
            fn (CashBox $b) => [$b->id => (string) $b->opening_balance]
        )->all();

        $quote = $this->postJson('/api/sales-quotes', [
            'quote_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'USD',
            'notes' => 'عرض تجاري فقط',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 1500, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->assertNotEmpty($quote->json('data.quote_number'));
        $this->assertDatabaseHas('sales_quotes', ['id' => $quote->json('data.id')]);

        $stockAfter = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $this->assertSame($stockBefore, $stockAfter);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($salesInvoicesBefore, SalesInvoice::query()->count());
        $this->assertSame($salesOrdersBefore, SalesOrder::query()->count());
        $this->assertSame($purchaseInvoicesBefore, PurchaseInvoice::query()->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());

        $cashBoxesAfter = CashBox::query()->get(['id', 'opening_balance'])->mapWithKeys(
            fn (CashBox $b) => [$b->id => (string) $b->opening_balance]
        )->all();
        $this->assertSame($cashBoxesBefore, $cashBoxesAfter);
    }

    public function test_quote_allows_optional_customer_and_multi_lines(): void
    {
        $productA = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $productB = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $res = $this->postJson('/api/sales-quotes', [
            'quote_date' => now()->toDateString(),
            'currency' => 'USD',
            'lines' => [
                ['product_id' => $productA->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
                ['product_id' => $productB->id, 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->assertNull($res->json('data.customer_id'));
        $this->assertCount(2, $res->json('data.items'));
        $this->assertEquals(200, (float) $res->json('data.total'));
    }

    public function test_stock_warnings_for_missing_and_excess_qty(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();

        // No stock → no_stock warning
        $empty = $this->postJson('/api/sales-quotes/stock-warnings', [
            'warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'product_name' => $product->name],
            ],
        ])->assertOk()->json('data');

        $this->assertCount(1, $empty);
        $this->assertSame('no_stock', $empty[0]['code']);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        $ok = $this->postJson('/api/sales-quotes/stock-warnings', [
            'warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'product_name' => $product->name],
            ],
        ])->assertOk()->json('data');
        $this->assertSame([], $ok);

        $excess = $this->postJson('/api/sales-quotes/stock-warnings', [
            'warehouse_id' => $warehouse->id,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 20, 'product_name' => $product->name],
            ],
        ])->assertOk()->json('data');

        $this->assertCount(1, $excess);
        $this->assertSame('qty_exceeds_stock', $excess[0]['code']);
        $this->assertEquals(5, (float) $excess[0]['available_qty']);
    }

    public function test_quotes_permissions_assigned_to_sales_role(): void
    {
        $sales = \Spatie\Permission\Models\Role::findByName('sales', 'web');
        $this->assertTrue($sales->hasPermissionTo('quotes.view'));
        $this->assertTrue($sales->hasPermissionTo('quotes.manage'));
    }
}
