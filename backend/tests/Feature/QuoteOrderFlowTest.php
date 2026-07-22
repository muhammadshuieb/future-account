<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\SalesQuote;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuoteOrderFlowTest extends TestCase
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

    public function test_quote_to_order_to_invoice_flow(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => Supplier::query()->where('code', 'SUP-001')->value('id'),
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $quote = $this->postJson('/api/sales-quotes', [
            'quote_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1200, 'tax_rate' => 0]],
        ])->assertCreated();

        $quoteId = $quote->json('data.id');
        $this->assertDatabaseHas('sales_quotes', ['id' => $quoteId]);

        $order = $this->postJson("/api/sales-quotes/{$quoteId}/convert-to-order")
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');

        $orderId = $order->json('data.id');
        $invoice = $this->postJson("/api/sales-orders/{$orderId}/convert-to-invoice", ['status' => 'posted'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $this->assertNotNull($invoice->json('data.e_invoice_uuid'));
        $this->assertSame('converted', SalesQuote::query()->find($quoteId)?->status);
    }

    public function test_purchase_request_to_order_flow(): void
    {
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $pr = $this->postJson('/api/purchase-requests', [
            'request_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 80, 'tax_rate' => 0]],
        ])->assertCreated();

        $prId = $pr->json('data.id');
        $po = $this->postJson("/api/purchase-requests/{$prId}/convert-to-order")
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('converted', PurchaseRequest::query()->find($prId)?->status);
        $this->assertNotNull($po->json('data.order_number'));
    }

    public function test_batch_serial_validation_on_sales_invoice(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $product->update(['track_batch' => true, 'track_serial' => false]);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => Supplier::query()->where('code', 'SUP-001')->value('id'),
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0, 'batch_no' => 'B1']],
        ])->assertCreated();

        // Outbound may omit batch_no — FIFO auto-assigns from available stock.
        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('sales_invoice_lines', [
            'product_id' => $product->id,
            'batch_no' => 'B1',
        ]);

        // Serial tracking still required when enabled.
        $product->update(['track_serial' => true]);
        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0, 'batch_no' => 'B1']],
        ])->assertStatus(422);

        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0, 'batch_no' => 'B1', 'serial_no' => 'SN-1']],
        ])->assertCreated();
    }

    public function test_sales_return_creation(): void
    {
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 140]],
        ])->assertCreated();
    }
}
