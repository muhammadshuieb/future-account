<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SalesInvoice;
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

class InvoiceSafeEditTest extends TestCase
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

    public function test_edit_posted_sales_invoice_keeps_stock_and_gl_consistent(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        $stockBeforeSale = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 200, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoiceId = $sales->json('data.id');
        $journalsAfterPost = JournalEntry::query()->where('status', 'posted')->count();
        $stockAfterSale = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');
        $this->assertEqualsWithDelta($stockBeforeSale - 3, $stockAfterSale, 0.001);

        $updated = $this->putJson("/api/sales-invoices/{$invoiceId}", [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 180, 'tax_rate' => 0]],
        ])->assertOk();

        $this->assertEquals(5, (float) $updated->json('data.lines.0.quantity'));
        $this->assertEquals(900, (float) $updated->json('data.total'));
        $this->assertSame('posted', $updated->json('data.status'));

        $stockAfterEdit = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');
        $this->assertEqualsWithDelta($stockBeforeSale - 5, $stockAfterEdit, 0.001);

        $invoice = SalesInvoice::query()->findOrFail($invoiceId);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertTrue($invoice->journalEntry->isBalanced());
        $this->assertSame('posted', $invoice->journalEntry->status);

        // Original JE voided; new JE posted — net posted count stays consistent enough to not leave orphans.
        $this->assertGreaterThanOrEqual($journalsAfterPost, JournalEntry::query()->where('status', 'posted')->count());
        $this->assertSame(1, JournalEntry::query()->where('status', 'void')->where('reference', $invoice->invoice_number)->count());
    }

    public function test_edit_posted_purchase_invoice_restores_then_reapplies_stock(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $stockBefore = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $purchase = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $id = $purchase->json('data.id');

        $this->putJson("/api/purchase-invoices/{$id}", [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 7, 'unit_cost' => 55, 'tax_rate' => 0]],
        ])->assertOk();

        $this->assertEquals(385, (float) $this->getJson("/api/purchase-invoices/{$id}")->json('data.total'));

        $stockAfter = (float) StockLevel::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->sum('quantity');

        $this->assertEqualsWithDelta($stockBefore + 7, $stockAfter, 0.001);
    }

    public function test_cannot_edit_sales_invoice_with_linked_return(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 150, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoiceId = $sales->json('data.id');

        $this->postJson('/api/sales-returns', [
            'return_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'sales_invoice_id' => $invoiceId,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->putJson("/api/sales-invoices/{$invoiceId}", [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 0]],
        ])->assertStatus(422);
    }
}
