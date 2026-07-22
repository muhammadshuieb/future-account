<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockAvailabilityTest extends TestCase
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

    public function test_posted_purchase_increases_stock_level(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 15, 'unit_cost' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_levels', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'batch_no' => '',
            'quantity' => 15,
        ]);
    }

    public function test_draft_purchase_does_not_increase_stock(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 80, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertDatabaseMissing('stock_levels', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_sales_order_convert_succeeds_when_stock_exists_in_default_warehouse(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $order = $this->postJson('/api/sales-orders', [
            'order_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1200, 'tax_rate' => 0]],
        ])->assertCreated();

        $orderId = $order->json('data.id');
        $this->assertSame($warehouse->id, SalesOrder::query()->findOrFail($orderId)->warehouse_id);

        $this->postJson("/api/sales-orders/{$orderId}/convert-to-invoice", ['status' => 'posted'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.warehouse_id', $warehouse->id);
    }

    public function test_sales_can_use_stock_from_non_default_batch_when_product_does_not_track_batch(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['track_batch' => false]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 8, 'unit_cost' => 800, 'tax_rate' => 0, 'batch_no' => 'LEGACY-LOT']],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_levels', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'batch_no' => '',
            'quantity' => 8,
        ]);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 1200, 'tax_rate' => 0]],
        ])->assertCreated();
    }

    public function test_insufficient_stock_error_includes_product_and_quantities(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $response = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 140, 'tax_rate' => 0]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', fn (string $message) => str_contains($message, $product->name)
                && str_contains($message, 'المطلوب')
                && str_contains($message, 'المتاح')
                && str_contains($message, $warehouse->name));

        $this->assertSame(0, PurchaseInvoice::query()->count());
    }

    public function test_insufficient_stock_hints_when_draft_purchase_exists(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-003')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => 25, 'tax_rate' => 0]],
        ])->assertCreated();

        $response = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 45, 'tax_rate' => 0]],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', fn (string $message) => str_contains($message, 'ترحيل فاتورة المشتريات'));
    }

    public function test_batch_tracked_sale_auto_assigns_fifo_when_requested_batch_unavailable(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['track_batch' => true]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 800, 'tax_rate' => 0, 'batch_no' => 'LOT-A']],
        ])->assertCreated();

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0, 'batch_no' => 'WRONG-BATCH']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('sales_invoice_lines', [
            'product_id' => $product->id,
            'batch_no' => 'LOT-A',
        ]);
    }

    public function test_sales_order_convert_uses_fifo_when_order_batch_unavailable(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['track_batch' => true]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0, 'batch_no' => '100']],
        ])->assertCreated();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0, 'batch_no' => '2']],
        ])->assertCreated();

        $order = $this->postJson('/api/sales-orders', [
            'order_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0, 'batch_no' => '20']],
        ])->assertCreated();

        $orderId = $order->json('data.id');

        $this->postJson("/api/sales-orders/{$orderId}/convert-to-invoice", ['status' => 'posted'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('sales_invoice_lines', [
            'product_id' => $product->id,
            'batch_no' => '100',
        ]);
    }

    public function test_product_stock_endpoint_returns_breakdown(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['track_batch' => true]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 800, 'tax_rate' => 0, 'batch_no' => 'B-1']],
        ])->assertCreated();

        $this->getJson("/api/products/{$product->id}/stock?warehouse_id={$warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.available_qty', 4)
            ->assertJsonPath('data.breakdown.0.batch_no', 'B-1')
            ->assertJsonPath('data.warehouse_name', $warehouse->name);
    }
}
