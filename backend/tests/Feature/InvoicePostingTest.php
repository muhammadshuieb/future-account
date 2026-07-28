<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
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

class InvoicePostingTest extends TestCase
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

    public function test_posts_purchase_then_sales_invoice_with_stock_and_balanced_journal(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $purchase = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 800, 'tax_rate' => 0],
            ],
        ]);

        $purchase->assertCreated()->assertJsonPath('data.status', 'posted');

        $this->assertDatabaseHas('stock_levels', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1200, 'tax_rate' => 0],
            ],
        ]);

        $sales->assertCreated()->assertJsonPath('data.status', 'posted');

        $invoice = SalesInvoice::query()->findOrFail($sales->json('data.id'));
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertTrue($invoice->journalEntry->isBalanced());
    }

    public function test_rejects_sales_without_enough_stock(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $response = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 140, 'tax_rate' => 0],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, SalesInvoice::query()->where('status', 'posted')->count());
        $this->assertSame(0, PurchaseInvoice::query()->count());
    }

    public function test_sales_and_purchase_invoices_persist_notes(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $purchaseNote = 'purchase note unique xyz';
        $salesNote = 'sales note unique xyz';

        $purchase = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'notes' => $purchaseNote,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 100, 'tax_rate' => 0],
            ],
        ]);
        $purchase->assertCreated()->assertJsonPath('data.notes', $purchaseNote);

        $purchaseId = $purchase->json('data.id');
        $this->getJson("/api/purchase-invoices/{$purchaseId}")
            ->assertOk()
            ->assertJsonPath('data.notes', $purchaseNote);

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'notes' => $salesNote,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 0],
            ],
        ]);
        $sales->assertCreated()->assertJsonPath('data.notes', $salesNote);

        $salesId = $sales->json('data.id');
        $this->getJson("/api/sales-invoices/{$salesId}")
            ->assertOk()
            ->assertJsonPath('data.notes', $salesNote);

        $this->getJson('/api/purchase-invoices?q=purchase+note+unique')
            ->assertOk()
            ->assertJsonPath('data.0.notes', $purchaseNote);
    }

    public function test_sales_invoice_discount_reduces_total_and_posts_gl(): void
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
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'discount_amount' => 150,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 1000, 'tax_rate' => 0],
            ],
        ]);

        $sales->assertCreated();

        $invoice = SalesInvoice::query()->findOrFail($sales->json('data.id'));
        $this->assertEqualsWithDelta(2000, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(150, (float) $invoice->discount_amount, 0.01);
        $this->assertEqualsWithDelta(1850, (float) $invoice->total, 0.01);
        $this->assertNotNull($invoice->journal_entry_id);
        $this->assertTrue($invoice->journalEntry->isBalanced());

        $details = $invoice->journalEntry->details()->with('account')->get();
        $salesCredit = (float) $details->firstWhere(fn ($d) => $d->account->code === '4101')?->credit;
        $discountDebit = (float) $details->firstWhere(fn ($d) => $d->account->code === '4104')?->debit;
        $arDebit = (float) $details->firstWhere(fn ($d) => (float) $d->debit > 0 && $d->account->code !== '4104' && $d->account->code !== '5101')?->debit;

        $this->assertEqualsWithDelta(1850, $arDebit, 0.01);
        $this->assertEqualsWithDelta(2000, $salesCredit, 0.01);
        $this->assertEqualsWithDelta(150, $discountDebit, 0.01);
    }

    public function test_sales_invoice_rejects_discount_above_subtotal(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $response = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'discount_amount' => 500,
            'status' => 'draft',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['discount_amount']);
        $this->assertSame(0, SalesInvoice::query()->count());
    }
}
