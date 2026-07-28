<?php

namespace Tests\Feature;

use App\Models\CashBox;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashService;
use App\Services\CurrencyService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashCreditDebtFlowTest extends TestCase
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
        app(CurrencyService::class)->ensureSeeded();

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_cash_sale_increases_main_cash_box_and_credit_increases_debt_only(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $cash = app(CashService::class);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 30, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $cashBefore = $cash->cashBoxCurrencyBalance($cashBox->fresh());
        $debtBefore = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');

        // Credit: debt up, cash unchanged
        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertEqualsWithDelta($cashBefore, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $debtAfterCredit = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');
        $this->assertEqualsWithDelta($debtBefore + 1000, $debtAfterCredit, 0.01);

        // Cash (no explicit cash_box_id → default main box): cash up, debt unchanged
        $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'cash',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 800, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertEqualsWithDelta($cashBefore + 800, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $debtAfterCash = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');
        $this->assertEqualsWithDelta($debtAfterCredit, $debtAfterCash, 0.01);
    }

    public function test_receipt_against_credit_increases_cash_and_reduces_debt(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $cash = app(CashService::class);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1500, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $cashBefore = $cash->cashBoxCurrencyBalance($cashBox->fresh());
        $debtBefore = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');
        $this->assertEqualsWithDelta(1500, $debtBefore, 0.01);

        // Collection without cash_box_id → main box
        $this->postJson('/api/receipts', [
            'receipt_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'sales_invoice_id' => $invoice['id'],
            'method' => 'cash',
            'amount' => 600,
            'status' => 'posted',
        ])->assertCreated();

        $this->assertEqualsWithDelta($cashBefore + 600, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $debtAfter = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');
        $this->assertEqualsWithDelta(900, $debtAfter, 0.01);

        $inv = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertEqualsWithDelta(600, (float) $inv->paid_amount, 0.01);
    }

    public function test_credit_purchase_does_not_change_cash_but_cash_purchase_does(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $cash = app(CashService::class);

        $cashStart = $cash->cashBoxCurrencyBalance($cashBox->fresh());

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 100, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertEqualsWithDelta($cashStart, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $this->assertEqualsWithDelta(300, (float) $this->getJson("/api/suppliers/{$supplier->id}")->json('data.balance'), 0.01);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'cash',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $this->assertEqualsWithDelta($cashStart - 100, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
    }

    public function test_supplier_debt_payment_decreases_main_cash_box(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $cash = app(CashService::class);

        $invoice = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 200, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $cashBeforePayment = $cash->cashBoxCurrencyBalance($cashBox->fresh());
        $apBefore = (float) $this->getJson("/api/suppliers/{$supplier->id}")->json('data.balance');
        $this->assertEqualsWithDelta(1000, $apBefore, 0.01);

        $this->postJson('/api/supplier-payments', [
            'payment_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice['id'],
            'method' => 'cash',
            'amount' => 400,
            'status' => 'posted',
        ])->assertCreated();

        $this->assertEqualsWithDelta($cashBeforePayment - 400, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $apAfter = (float) $this->getJson("/api/suppliers/{$supplier->id}")->json('data.balance');
        $this->assertEqualsWithDelta(600, $apAfter, 0.01);
    }

    public function test_collect_remaining_closes_credit_sales_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $cash = app(CashService::class);

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1200, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(0, (float) $invoice['paid_amount'], 0.01);

        $cashBefore = $cash->cashBoxCurrencyBalance($cashBox->fresh());
        $debtBefore = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');
        $this->assertEqualsWithDelta(1200, $debtBefore, 0.01);

        $res = $this->postJson("/api/sales-invoices/{$invoice['id']}/collect", [
            'amount' => 1200,
            'cash_box_id' => $cashBox->id,
            'method' => 'cash',
        ])->assertCreated()->json('data');

        $this->assertEquals('posted', $res['receipt']['status']);
        $this->assertEqualsWithDelta(1200, (float) $res['receipt']['amount'], 0.01);
        $this->assertEqualsWithDelta(1200, (float) $res['invoice']['paid_amount'], 0.01);
        $this->assertEqualsWithDelta(1200, (float) $res['invoice']['total'], 0.01);

        $this->assertEqualsWithDelta($cashBefore + 1200, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $debtAfter = (float) $this->getJson("/api/customers/{$customer->id}")->json('data.balance');
        $this->assertEqualsWithDelta(0, $debtAfter, 0.01);

        $inv = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertEqualsWithDelta(1200, (float) $inv->paid_amount, 0.01);
        $this->assertEquals('posted', $inv->status);
        $this->assertEquals('credit', $inv->payment_type);
    }

    public function test_collect_remaining_rejects_overpay_and_partial_works(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 15, 'unit_cost' => 50, 'tax_rate' => 0]],
        ])->assertCreated();

        $invoice = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'partial',
            'paid_amount' => 300,
            'cash_box_id' => $cashBox->id,
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(300, (float) $invoice['paid_amount'], 0.01);

        $this->postJson("/api/sales-invoices/{$invoice['id']}/collect", [
            'amount' => 800,
        ])->assertStatus(422);

        $this->postJson("/api/sales-invoices/{$invoice['id']}/collect", [
            'amount' => 400,
            'cash_box_id' => $cashBox->id,
        ])->assertCreated();

        $inv = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertEqualsWithDelta(700, (float) $inv->paid_amount, 0.01);

        $this->postJson("/api/sales-invoices/{$invoice['id']}/collect")->assertCreated();

        $inv->refresh();
        $this->assertEqualsWithDelta(1000, (float) $inv->paid_amount, 0.01);

        $this->postJson("/api/sales-invoices/{$invoice['id']}/collect")->assertStatus(422);
    }

    public function test_pay_remaining_closes_credit_purchase_invoice(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();
        $cashBox = CashBox::query()->where('code', 'CASH-01')->firstOrFail();
        $cash = app(CashService::class);

        $invoice = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [['product_id' => $product->id, 'quantity' => 4, 'unit_cost' => 250, 'tax_rate' => 0]],
        ])->assertCreated()->json('data');

        $cashBefore = $cash->cashBoxCurrencyBalance($cashBox->fresh());
        $apBefore = (float) $this->getJson("/api/suppliers/{$supplier->id}")->json('data.balance');
        $this->assertEqualsWithDelta(1000, $apBefore, 0.01);

        $res = $this->postJson("/api/purchase-invoices/{$invoice['id']}/pay-remaining", [
            'amount' => 1000,
            'cash_box_id' => $cashBox->id,
            'method' => 'cash',
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(1000, (float) $res['payment']['amount'], 0.01);
        $this->assertEqualsWithDelta(1000, (float) $res['invoice']['paid_amount'], 0.01);
        $this->assertEqualsWithDelta($cashBefore - 1000, $cash->cashBoxCurrencyBalance($cashBox->fresh()), 0.01);
        $apAfter = (float) $this->getJson("/api/suppliers/{$supplier->id}")->json('data.balance');
        $this->assertEqualsWithDelta(0, $apAfter, 0.01);
    }
}
