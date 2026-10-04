<?php

namespace Tests\Feature;

use App\Models\CashBox;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unallocated partner settlements must FIFO onto open invoices so paid_amount
 * matches partner balance when the account is closed.
 */
class SettlementInvoiceAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected Supplier $supplier;

    protected Product $product;

    protected CashBox $cashBox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);
        app(CurrencyService::class)->ensureSeeded();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $this->customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $this->supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $this->product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $this->cashBox = CashBox::query()->where('code', 'CASH-USD')->firstOrFail();
    }

    public function test_unallocated_supplier_payment_closes_open_purchase_invoices_fifo(): void
    {
        $inv1 = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->subDays(2)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 300, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $inv2 = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->subDay()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 200, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $pay = $this->postJson('/api/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'purchase_invoice_id' => null,
            'cash_box_id' => $this->cashBox->id,
            'method' => 'cash',
            'amount' => 500,
            'status' => 'posted',
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(500, (float) $pay['applied_amount'], 0.01);

        $i1 = PurchaseInvoice::query()->findOrFail($inv1['id']);
        $i2 = PurchaseInvoice::query()->findOrFail($inv2['id']);
        $this->assertEqualsWithDelta(300, (float) $i1->paid_amount, 0.01);
        $this->assertEqualsWithDelta(200, (float) $i2->paid_amount, 0.01);

        $statement = $this->getJson("/api/suppliers/{$this->supplier->id}/statement")->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $statement['closing_balance'], 0.02);

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $dashboard['payables'], 0.02);
    }

    public function test_unallocated_receipt_closes_open_sales_invoices_fifo(): void
    {
        // stock
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 10, 'tax_rate' => 0]],
        ])->assertCreated();

        $inv1 = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->subDays(2)->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $inv2 = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->subDay()->toDateString(),
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 185, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $rc = $this->postJson('/api/receipts', [
            'receipt_date' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'sales_invoice_id' => null,
            'cash_box_id' => $this->cashBox->id,
            'method' => 'cash',
            'amount' => 485,
            'status' => 'posted',
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(485, (float) $rc['applied_amount'], 0.01);

        $i1 = SalesInvoice::query()->findOrFail($inv1['id']);
        $i2 = SalesInvoice::query()->findOrFail($inv2['id']);
        $this->assertEqualsWithDelta(300, (float) $i1->paid_amount, 0.01);
        $this->assertEqualsWithDelta(185, (float) $i2->paid_amount, 0.01);

        $statement = $this->getJson("/api/customers/{$this->customer->id}/statement")->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $statement['closing_balance'], 0.02);
    }
}
