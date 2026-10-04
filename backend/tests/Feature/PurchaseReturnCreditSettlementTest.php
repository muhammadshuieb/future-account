<?php

namespace Tests\Feature;

use App\Models\CashBox;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
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

/**
 * Paid purchase return must allocate credit to open invoices (FIFO) so pay-remaining
 * matches partner balance; leftover cash is refunded only when nothing open remains.
 */
class PurchaseReturnCreditSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected Warehouse $warehouse;

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
        $this->supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $this->product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $this->cashBox = CashBox::query()->where('code', 'CASH-USD')->firstOrFail();
    }

    public function test_paid_invoice_return_allocates_to_open_credit_invoice_without_cash_refund(): void
    {
        $cash = app(CashService::class);
        $cashBefore = $cash->cashBoxCurrencyBalance($this->cashBox->fresh());

        // Invoice #1 — cash paid $855
        $inv1 = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->subDays(2)->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'cash',
            'cash_box_id' => $this->cashBox->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 855, 'tax_rate' => 0],
            ],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta($cashBefore - 855, $cash->cashBoxCurrencyBalance($this->cashBox->fresh()), 0.01);

        // Invoice #2 — credit unpaid $82.2
        $inv2 = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->subDay()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 82.2, 'tax_rate' => 0],
            ],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(0, (float) $inv2['paid_amount'], 0.01);

        // Return $37.44 against paid invoice #1
        $ret = $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'purchase_invoice_id' => $inv1['id'],
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 37.44],
            ],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(37.44, (float) $ret['applied_amount'], 0.01);
        $this->assertEqualsWithDelta(0, (float) $ret['refund_amount'], 0.01);

        $inv2Fresh = PurchaseInvoice::query()->findOrFail($inv2['id']);
        $this->assertEqualsWithDelta(37.44, (float) $inv2Fresh->paid_amount, 0.01);
        $remaining = round((float) $inv2Fresh->total - (float) $inv2Fresh->paid_amount, 2);
        $this->assertEqualsWithDelta(44.76, $remaining, 0.01);

        // Cash unchanged — credit absorbed by open invoice
        $this->assertEqualsWithDelta($cashBefore - 855, $cash->cashBoxCurrencyBalance($this->cashBox->fresh()), 0.01);

        $statement = $this->getJson("/api/suppliers/{$this->supplier->id}/statement")->assertOk()->json('data');
        $this->assertEqualsWithDelta(44.76, (float) $statement['closing_balance'], 0.02);

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json('data');
        $this->assertEqualsWithDelta(44.76, (float) $dashboard['payables'], 0.02);
    }

    public function test_paid_invoice_return_with_no_open_invoices_refunds_cash(): void
    {
        $cash = app(CashService::class);
        $cashBefore = $cash->cashBoxCurrencyBalance($this->cashBox->fresh());

        $inv1 = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'cash',
            'cash_box_id' => $this->cashBox->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 200, 'tax_rate' => 0],
            ],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta($cashBefore - 200, $cash->cashBoxCurrencyBalance($this->cashBox->fresh()), 0.01);

        $ret = $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'purchase_invoice_id' => $inv1['id'],
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 50],
            ],
        ])->assertCreated()->json('data');

        $this->assertEqualsWithDelta(0, (float) $ret['applied_amount'], 0.01);
        $this->assertEqualsWithDelta(50, (float) $ret['refund_amount'], 0.01);
        $this->assertSame((int) $this->cashBox->id, (int) $ret['cash_box_id']);

        // Cash paid 200, then refund 50 → net -150 from start
        $this->assertEqualsWithDelta($cashBefore - 150, $cash->cashBoxCurrencyBalance($this->cashBox->fresh()), 0.01);

        $statement = $this->getJson("/api/suppliers/{$this->supplier->id}/statement")->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $statement['closing_balance'], 0.02);
    }

    public function test_credit_invoice_return_applies_to_source_invoice_remaining(): void
    {
        $inv = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 100, 'tax_rate' => 0],
            ],
        ])->assertCreated()->json('data');

        $this->postJson('/api/purchase-returns', [
            'return_date' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'purchase_invoice_id' => $inv['id'],
            'warehouse_id' => $this->warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 40],
            ],
        ])->assertCreated();

        $fresh = PurchaseInvoice::query()->findOrFail($inv['id']);
        $this->assertEqualsWithDelta(40, (float) $fresh->paid_amount, 0.01);
        $this->assertEqualsWithDelta(60, round((float) $fresh->total - (float) $fresh->paid_amount, 2), 0.01);

        $ret = PurchaseReturn::query()->first();
        $this->assertEqualsWithDelta(40, (float) $ret->applied_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $ret->refund_amount, 0.01);
    }
}
