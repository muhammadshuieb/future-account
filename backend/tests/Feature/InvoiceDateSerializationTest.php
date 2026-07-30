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

class InvoiceDateSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Damascus']);

        $this->seed([
            RolesAndPermissionsSeeder::class,
            ChartOfAccountsSeeder::class,
            ErpDemoSeeder::class,
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_sales_invoice_date_json_is_ymd_not_utc_shifted(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $customer = Customer::query()->where('code', 'CUS-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $this->postJson('/api/purchase-invoices', [
            'invoice_date' => '2026-07-28',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 100, 'tax_rate' => 0],
            ],
        ])->assertCreated();

        $sales = $this->postJson('/api/sales-invoices', [
            'invoice_date' => '2026-07-28',
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 0],
            ],
        ]);

        $sales->assertCreated()->assertJsonPath('data.invoice_date', '2026-07-28');

        $this->getJson('/api/sales-invoices/'.$sales->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.invoice_date', '2026-07-28');

        $model = SalesInvoice::query()->findOrFail($sales->json('data.id'));
        $this->assertSame('2026-07-28', $model->toArray()['invoice_date']);
    }

    public function test_purchase_invoice_date_json_is_ymd_not_utc_shifted(): void
    {
        $warehouse = Warehouse::query()->where('code', 'WH-01')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'SUP-001')->firstOrFail();
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $purchase = $this->postJson('/api/purchase-invoices', [
            'invoice_date' => '2026-07-28',
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'posted',
            'lines' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => 80, 'tax_rate' => 0],
            ],
        ]);

        $purchase->assertCreated()->assertJsonPath('data.invoice_date', '2026-07-28');

        $this->getJson('/api/purchase-invoices/'.$purchase->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.invoice_date', '2026-07-28');

        $model = PurchaseInvoice::query()->findOrFail($purchase->json('data.id'));
        $this->assertSame('2026-07-28', $model->toArray()['invoice_date']);
    }
}
