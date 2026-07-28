<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalDetail;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseInvoiceExtrasTest extends TestCase
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

    public function test_purchase_invoice_extras_increase_total_and_capitalize_into_inventory(): void
    {
        Setting::setValue('tax_enabled', '0', 'finance', 'boolean', 'تفعيل الضريبة');

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $response = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'posted',
            'customs_amount' => 50,
            'transport_fees' => 30,
            'fines_amount' => 10,
            'other_fees' => 20,
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100, 'tax_rate' => 15],
            ],
        ]);

        $response->assertCreated();
        $invoice = PurchaseInvoice::query()->findOrFail($response->json('data.id'));

        $this->assertEqualsWithDelta(1000, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->tax_amount, 0.01);
        $this->assertEqualsWithDelta(50, (float) $invoice->customs_amount, 0.01);
        $this->assertEqualsWithDelta(30, (float) $invoice->transport_fees, 0.01);
        $this->assertEqualsWithDelta(10, (float) $invoice->fines_amount, 0.01);
        $this->assertEqualsWithDelta(20, (float) $invoice->other_fees, 0.01);
        // 1000 lines + 110 extras, tax ignored while disabled
        $this->assertEqualsWithDelta(1110, (float) $invoice->total, 0.01);
        $this->assertEqualsWithDelta(110, $invoice->extrasTotal(), 0.01);

        $inventory = Account::query()->where('code', '1104')->firstOrFail();
        $ap = $supplier->account_id
            ? Account::query()->findOrFail($supplier->account_id)
            : Account::query()->where('code', '2101')->firstOrFail();

        $invDebit = (float) JournalDetail::query()
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->where('account_id', $inventory->id)
            ->sum('debit');
        $apCredit = (float) JournalDetail::query()
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->where('account_id', $ap->id)
            ->sum('credit');

        $this->assertEqualsWithDelta(1110, $invDebit, 0.01);
        $this->assertEqualsWithDelta(1110, $apCredit, 0.01);

        // No VAT line when tax is zero
        $vatInput = Account::query()->where('code', '1106')->firstOrFail();
        $vatDebit = (float) JournalDetail::query()
            ->where('journal_entry_id', $invoice->journal_entry_id)
            ->where('account_id', $vatInput->id)
            ->sum('debit');
        $this->assertEqualsWithDelta(0, $vatDebit, 0.01);

        // Unit cost includes allocated extras: (1000 + 110) / 10 = 111
        $product->refresh();
        $this->assertEqualsWithDelta(111, (float) $product->cost_price, 0.01);
    }

    public function test_tax_disabled_ignores_line_tax_rate_on_purchases(): void
    {
        Setting::setValue('tax_enabled', '0', 'finance', 'boolean', 'تفعيل الضريبة');
        Setting::setValue('tax_rate', '15', 'finance', 'number', 'نسبة الضريبة %');

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $response = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'draft',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => 50, 'tax_rate' => 15],
            ],
        ]);

        $response->assertCreated();
        $invoice = PurchaseInvoice::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(100, (float) $invoice->subtotal, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->tax_amount, 0.01);
        $this->assertEqualsWithDelta(100, (float) $invoice->total, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->lines()->first()->tax_rate, 0.01);
    }

    public function test_optional_extras_default_to_zero(): void
    {
        Setting::setValue('tax_enabled', '0', 'finance', 'boolean', 'تفعيل الضريبة');

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $response = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'payment_type' => 'credit',
            'status' => 'draft',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 40],
            ],
        ]);

        $response->assertCreated();
        $invoice = PurchaseInvoice::query()->findOrFail($response->json('data.id'));
        $this->assertEqualsWithDelta(0, (float) $invoice->customs_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->transport_fees, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->fines_amount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $invoice->other_fees, 0.01);
        $this->assertEqualsWithDelta(40, (float) $invoice->total, 0.01);
    }
}
