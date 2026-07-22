<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxEnabledSettingTest extends TestCase
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

    public function test_tax_disabled_forces_zero_tax_on_sales_invoice(): void
    {
        Setting::setValue('tax_enabled', '0', 'finance', 'boolean', 'تفعيل الضريبة');
        Setting::setValue('tax_rate', '15', 'finance', 'number', 'نسبة الضريبة %');

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        // Stock via purchase with tax disabled as well
        $supplier = \App\Models\Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 100, 'tax_rate' => 15],
            ],
        ])->assertCreated();

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 15],
            ],
        ]);

        $sales->assertCreated();
        $invoice = SalesInvoice::query()->findOrFail($sales->json('data.id'));

        $this->assertSame(0.0, (float) $invoice->tax_amount);
        $this->assertSame(200.0, (float) $invoice->subtotal);
        $this->assertSame(200.0, (float) $invoice->total);
        $this->assertSame(0.0, (float) $invoice->lines()->first()->tax_rate);
    }

    public function test_tax_enabled_applies_line_tax_rate(): void
    {
        Setting::setValue('tax_enabled', '1', 'finance', 'boolean', 'تفعيل الضريبة');
        Setting::setValue('tax_rate', '15', 'finance', 'number', 'نسبة الضريبة %');

        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();
        $supplier = \App\Models\Supplier::query()->where('code', 'SUP-001')->firstOrFail();

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
            'status' => 'draft',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 15],
            ],
        ]);

        $sales->assertCreated();
        $invoice = SalesInvoice::query()->findOrFail($sales->json('data.id'));

        $this->assertSame(200.0, (float) $invoice->subtotal);
        $this->assertSame(30.0, (float) $invoice->tax_amount);
        $this->assertSame(230.0, (float) $invoice->total);
    }

    public function test_bootstrap_exposes_tax_and_locale_defaults(): void
    {
        Setting::setValue('tax_enabled', '1', 'finance', 'boolean', 'تفعيل الضريبة');
        Setting::setValue('default_locale', 'tr', 'general', 'string', 'اللغة الافتراضية');

        $this->getJson('/api/public/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.tax_enabled', true)
            ->assertJsonPath('data.default_locale', 'tr');
    }
}
