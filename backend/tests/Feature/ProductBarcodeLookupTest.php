<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductBarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_lookup_product_by_exact_barcode(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        Product::query()->create([
            'sku' => 'SKU-001',
            'barcode' => '6281000000999',
            'name' => 'Test Product',
            'cost_price' => 10,
            'sale_price' => 15,
            'is_active' => true,
        ]);

        $this->getJson('/api/products?barcode=6281000000999')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.barcode', '6281000000999');
    }

    public function test_barcode_lookup_returns_empty_when_not_found(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/products?barcode=NOTFOUND')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
