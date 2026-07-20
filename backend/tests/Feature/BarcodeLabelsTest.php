<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ErpDemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BarcodeLabelsTest extends TestCase
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

    public function test_labels_endpoint_returns_products(): void
    {
        $product = Product::query()->where('sku', 'PRD-001')->firstOrFail();

        $res = $this->getJson('/api/barcodes/labels?product_ids[]='.$product->id);

        $res->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.labels.0.sku', 'PRD-001');
        $this->assertNotEmpty($res->json('data.labels.0.barcode'));
    }

    public function test_generate_barcode_for_product(): void
    {
        $product = Product::query()->where('sku', 'PRD-002')->firstOrFail();

        $res = $this->postJson('/api/products/'.$product->id.'/barcode', [
            'save' => true,
        ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('data.barcode'));
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'barcode' => $res->json('data.barcode'),
        ]);
    }
}
