<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseIsolationTest extends TestCase
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

    public function test_sale_from_warehouse_a_does_not_reduce_warehouse_b(): void
    {
        $whA = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whB = Warehouse::query()->where('code', 'WH-02')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whA->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 7, 'unit_cost' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $whA->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 1200, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertSame(6.0, (float) StockLevel::query()
            ->where('warehouse_id', $whA->id)
            ->where('product_id', $product->id)
            ->sum('quantity'));

        $this->assertSame(7.0, (float) StockLevel::query()
            ->where('warehouse_id', $whB->id)
            ->where('product_id', $product->id)
            ->sum('quantity'));
    }

    public function test_sale_availability_is_scoped_to_selected_warehouse(): void
    {
        $whA = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whB = Warehouse::query()->where('code', 'WH-02')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 80, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->getJson("/api/products/{$product->id}/stock?warehouse_id={$whA->id}")
            ->assertOk()
            ->assertJsonPath('data.available_qty', 0)
            ->assertJsonPath('data.warehouse_id', $whA->id);

        $this->getJson("/api/products/{$product->id}/stock?warehouse_id={$whB->id}")
            ->assertOk()
            ->assertJsonPath('data.available_qty', 20)
            ->assertJsonPath('data.warehouse_id', $whB->id);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $whA->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 140, 'tax_rate' => 0]],
        ])->assertStatus(422)
            ->assertJsonPath('errors.quantity.0', fn (string $message) => str_contains($message, $whA->name)
                && str_contains($message, $whB->name));

        $this->assertSame(20.0, (float) StockLevel::query()
            ->where('warehouse_id', $whB->id)
            ->where('product_id', $product->id)
            ->sum('quantity'));
    }

    public function test_sales_and_purchase_invoices_require_warehouse_id(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 10, 'tax_rate' => 0]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_stock_alerts_and_inventory_report_are_warehouse_scoped(): void
    {
        $whA = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whB = Warehouse::query()->where('code', 'WH-02')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-003')->firstOrFail();
        $product->update(['reorder_level' => 5]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whA->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 25, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 25, 'tax_rate' => 0]],
        ])->assertCreated();

        $alertsA = $this->getJson("/api/stock-alerts?warehouse_id={$whA->id}")
            ->assertOk()
            ->json('data');

        $this->assertTrue(collect($alertsA)->contains(
            fn (array $row) => (int) $row['product_id'] === $product->id
                && (int) $row['warehouse_id'] === $whA->id
                && (float) $row['on_hand'] === 2.0
        ));

        $alertsB = $this->getJson("/api/stock-alerts?warehouse_id={$whB->id}")
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($alertsB)->contains(
            fn (array $row) => (int) $row['product_id'] === $product->id
        ));

        $inventoryA = $this->getJson("/api/reports/inventory?warehouse_id={$whA->id}")
            ->assertOk()
            ->json('data.rows');

        $rowA = collect($inventoryA)->firstWhere('id', $product->id);
        $this->assertNotNull($rowA);
        $this->assertSame(2.0, (float) $rowA['on_hand']);

        $inventoryB = $this->getJson("/api/reports/inventory?warehouse_id={$whB->id}")
            ->assertOk()
            ->json('data.rows');

        $rowB = collect($inventoryB)->firstWhere('id', $product->id);
        $this->assertNotNull($rowB);
        $this->assertSame(20.0, (float) $rowB['on_hand']);
    }

    public function test_transfer_is_only_way_to_move_stock_between_warehouses(): void
    {
        $whA = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $whB = Warehouse::query()->where('code', 'WH-02')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $whA->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $transfer = $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $whA->id,
            'to_warehouse_id' => $whB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame('posted', $transfer->json('data.status'));

        $this->assertSame(2.0, (float) StockLevel::query()
            ->where('warehouse_id', $whA->id)
            ->where('product_id', $product->id)
            ->sum('quantity'));

        $this->assertSame(3.0, (float) StockLevel::query()
            ->where('warehouse_id', $whB->id)
            ->where('product_id', $product->id)
            ->sum('quantity'));
    }
}
