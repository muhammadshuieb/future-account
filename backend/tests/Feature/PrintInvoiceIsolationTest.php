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

class PrintInvoiceIsolationTest extends TestCase
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

    public function test_creating_print_invoice_does_not_affect_stock_cash_sales_or_gl(): void
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

        $invoice = $this->postJson('/api/print-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'currency' => 'USD',
            'notes' => 'فاتورة طباعة فقط',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 1500, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $this->assertNotEmpty($invoice->json('data.invoice_number'));
        $this->assertDatabaseHas('print_invoices', ['id' => $invoice->json('data.id')]);

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

    public function test_print_invoice_allows_optional_customer_and_multi_lines(): void
    {
        $productA = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $productB = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $res = $this->postJson('/api/print-invoices', [
            'invoice_date' => now()->toDateString(),
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

    public function test_print_invoices_permissions_assigned_to_sales_role(): void
    {
        $sales = \Spatie\Permission\Models\Role::findByName('sales', 'web');
        $this->assertTrue($sales->hasPermissionTo('print_invoices.view'));
        $this->assertTrue($sales->hasPermissionTo('print_invoices.manage'));
    }
}
