<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCreateWithWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);
    }

    public function test_create_product_requires_warehouse(): void
    {
        $this->postJson('/api/products', [
            'sku' => 'NEW-001',
            'name' => 'صنف جديد',
            'cost_price' => 10,
            'sale_price' => 15,
            'is_active' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_create_product_registers_zero_stock_in_warehouse(): void
    {
        $wh = Warehouse::query()->create([
            'code' => 'WH-T1',
            'name' => 'مخزن اختبار',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/products', [
            'sku' => 'NEW-002',
            'name' => 'صنف بلا كمية',
            'cost_price' => 5,
            'sale_price' => 8,
            'warehouse_id' => $wh->id,
            'opening_quantity' => 0,
            'is_active' => true,
        ])->assertCreated();

        $productId = (int) $res->json('data.id');
        $this->assertDatabaseHas('stock_levels', [
            'product_id' => $productId,
            'warehouse_id' => $wh->id,
            'quantity' => 0,
        ]);
        $this->assertSame(0, StockMovement::query()->where('product_id', $productId)->count());
    }

    public function test_create_product_with_opening_quantity_posts_stock_in(): void
    {
        $wh = Warehouse::query()->create([
            'code' => 'WH-T2',
            'name' => 'مخزن افتتاحي',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/products', [
            'sku' => 'NEW-003',
            'name' => 'صنف بكمية',
            'brand' => 'سامسونج',
            'model' => 'A54',
            'cost_price' => 12,
            'sale_price' => 20,
            'warehouse_id' => $wh->id,
            'opening_quantity' => 25,
            'is_active' => true,
        ])->assertCreated();

        $productId = (int) $res->json('data.id');
        $this->assertSame(25.0, (float) StockLevel::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $wh->id)
            ->sum('quantity'));

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $productId,
            'warehouse_id' => $wh->id,
            'type' => 'in',
        ]);

        $product = Product::query()->findOrFail($productId);
        $this->assertSame('NEW-003', $product->sku);
        $this->assertSame('سامسونج', $product->brand);
        $this->assertSame('A54', $product->model);
    }

    public function test_create_product_without_sku_auto_generates_prd_code(): void
    {
        $wh = Warehouse::query()->create([
            'code' => 'WH-SKU',
            'name' => 'مخزن SKU',
            'is_active' => true,
        ]);

        Product::query()->create([
            'sku' => 'PRD-00007',
            'name' => 'موجود مسبقاً',
            'cost_price' => 1,
            'sale_price' => 2,
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/products', [
            'name' => 'صنف بدون SKU',
            'cost_price' => 3,
            'sale_price' => 5,
            'warehouse_id' => $wh->id,
            'opening_quantity' => 0,
            'is_active' => true,
        ])->assertCreated();

        $this->assertSame('PRD-00008', $res->json('data.sku'));
        $this->assertDatabaseHas('products', [
            'id' => (int) $res->json('data.id'),
            'sku' => 'PRD-00008',
            'name' => 'صنف بدون SKU',
        ]);
    }

    public function test_create_product_with_empty_sku_auto_generates(): void
    {
        $wh = Warehouse::query()->create([
            'code' => 'WH-SKU2',
            'name' => 'مخزن SKU فارغ',
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/products', [
            'sku' => '',
            'name' => 'صنف SKU فارغ',
            'cost_price' => 1,
            'sale_price' => 2,
            'warehouse_id' => $wh->id,
            'is_active' => true,
        ])->assertCreated();

        $sku = (string) $res->json('data.sku');
        $this->assertMatchesRegularExpression('/^PRD-\d{5}$/', $sku);
    }

    public function test_update_product_brand_and_model(): void
    {
        $wh = Warehouse::query()->create([
            'code' => 'WH-T3',
            'name' => 'مخزن تحديث',
            'is_active' => true,
        ]);

        $create = $this->postJson('/api/products', [
            'sku' => 'UPD-001',
            'name' => 'صنف للتعديل',
            'cost_price' => 1,
            'sale_price' => 2,
            'warehouse_id' => $wh->id,
            'opening_quantity' => 0,
            'is_active' => true,
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->putJson("/api/products/{$id}", [
            'sku' => 'UPD-001',
            'name' => 'صنف للتعديل',
            'brand' => 'آبل',
            'model' => 'iPhone 15',
            'cost_price' => 1,
            'sale_price' => 2,
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'brand' => 'آبل',
            'model' => 'iPhone 15',
        ]);
    }
}
