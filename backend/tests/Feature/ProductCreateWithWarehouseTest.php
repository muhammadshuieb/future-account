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
    }
}
