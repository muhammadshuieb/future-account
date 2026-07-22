<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SafeDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class, ErpDemoSeeder::class]);
        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);
    }

    public function test_can_delete_draft_sales_invoice(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $created = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/sales-invoices/{$created['id']}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'تم حذف فاتورة المبيعات.']);

        $this->assertDatabaseMissing('sales_invoices', ['id' => $created['id']]);
    }

    public function test_cannot_delete_posted_sales_invoice(): void
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
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $created = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/sales-invoices/{$created['id']}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('sales_invoices', [
            'id' => $created['id'],
            'status' => 'posted',
        ]);
    }

    public function test_can_delete_draft_purchase_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $created = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 40, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/purchase-invoices/{$created['id']}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'تم حذف فاتورة المشتريات.']);

        $this->assertDatabaseMissing('purchase_invoices', ['id' => $created['id']]);
    }

    public function test_cannot_delete_posted_purchase_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $created = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 40, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->deleteJson("/api/purchase-invoices/{$created['id']}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $created['id'],
            'status' => 'posted',
        ]);
    }

    public function test_can_delete_unused_product(): void
    {
        $product = Product::query()->create([
            'sku' => 'PRD-SAFE-DEL',
            'name' => 'صنف للحذف الآمن',
            'cost_price' => 10,
            'sale_price' => 20,
            'reorder_level' => 0,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
        ]);

        AuditLog::query()->delete();

        $this->deleteJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonFragment(['message' => 'تم حذف الصنف.']);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'product.deleted',
            'auditable_type' => Product::class,
            'auditable_id' => $product->id,
        ]);
    }

    public function test_cannot_delete_product_used_in_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->create([
            'sku' => 'PRD-USED-DEL',
            'name' => 'صنف مستخدم',
            'cost_price' => 10,
            'sale_price' => 20,
            'reorder_level' => 0,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
        ]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 10, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->deleteJson("/api/products/{$product->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product']);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_deleting_draft_sales_invoice_writes_audit_log(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $created = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        AuditLog::query()->delete();

        $this->deleteJson("/api/sales-invoices/{$created['id']}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'sales_invoice.deleted',
            'auditable_type' => SalesInvoice::class,
            'auditable_id' => $created['id'],
        ]);
    }

    public function test_cannot_delete_converted_sales_quote(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $quote = $this->postJson('/api/sales-quotes', [
            'quote_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/sales-quotes/{$quote['id']}/convert-to-order")->assertCreated();

        $this->deleteJson("/api/sales-quotes/{$quote['id']}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }
}
