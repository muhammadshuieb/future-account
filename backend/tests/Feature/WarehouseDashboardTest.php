<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseApprovalRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_returns_role_specific_landing_path(): void
    {
        $manager = User::factory()->create([
            'username' => 'warehouse.manager',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $manager->assignRole('warehouse_manager');

        $admin = User::factory()->create([
            'username' => 'admin.user',
            'password' => 'password123',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $this->postJson('/api/auth/login', [
            'username' => 'warehouse.manager',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('landing_path', '/warehouse-dashboard');

        $this->postJson('/api/auth/login', [
            'username' => 'admin.user',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('landing_path', '/');
    }

    public function test_dashboard_returns_only_assigned_warehouse_data(): void
    {
        $assigned = Warehouse::query()->create(['code' => 'A', 'name' => 'Assigned', 'is_active' => true]);
        $hidden = Warehouse::query()->create(['code' => 'B', 'name' => 'Hidden', 'is_active' => true]);

        $assignedProduct = Product::query()->create([
            'sku' => 'ASSIGNED',
            'name' => 'Assigned product',
            'cost_price' => 12,
            'sale_price' => 15,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
        $hiddenProduct = Product::query()->create([
            'sku' => 'HIDDEN',
            'name' => 'Hidden product',
            'cost_price' => 100,
            'sale_price' => 120,
            'reorder_level' => 10,
            'is_active' => true,
        ]);
        StockLevel::query()->create([
            'warehouse_id' => $assigned->id,
            'product_id' => $assignedProduct->id,
            'batch_no' => '',
            'quantity' => 4,
        ]);
        StockLevel::query()->create([
            'warehouse_id' => $hidden->id,
            'product_id' => $hiddenProduct->id,
            'batch_no' => '',
            'quantity' => 2,
        ]);

        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole('warehouse_manager');
        $manager->warehouses()->attach($assigned);

        WarehouseApprovalRequest::query()->create([
            'requester_id' => $manager->id,
            'warehouse_id' => $assigned->id,
            'action_type' => 'warehouse_transfer.create',
            'after_payload' => [],
            'source_hash' => hash('sha256', 'assigned'),
            'status' => 'pending',
        ]);
        WarehouseApprovalRequest::query()->create([
            'requester_id' => $manager->id,
            'warehouse_id' => $hidden->id,
            'action_type' => 'warehouse_transfer.create',
            'after_payload' => [],
            'source_hash' => hash('sha256', 'hidden'),
            'status' => 'pending',
        ]);

        Sanctum::actingAs($manager);
        $response = $this->getJson('/api/warehouse-dashboard/summary')
            ->assertOk()
            ->assertJsonCount(1, 'data.warehouses')
            ->assertJsonPath('data.warehouses.0.id', $assigned->id)
            ->assertJsonPath('data.selected_warehouse_id', $assigned->id)
            ->assertJsonPath('data.product_count', 1)
            ->assertJsonPath('data.stock_quantity', 4)
            ->assertJsonPath('data.stock_value', 48)
            ->assertJsonPath('data.low_stock_count', 1)
            ->assertJsonPath('data.pending_request_count', 1);

        $this->assertSame('ASSIGNED', $response->json('data.low_stock_alerts.0.sku'));
        $this->assertSame($assigned->id, $response->json('data.recent_requests.0.warehouse_id'));
        $this->getJson("/api/warehouse-dashboard/summary?warehouse_id={$hidden->id}")->assertForbidden();
    }

    public function test_non_warehouse_manager_cannot_open_warehouse_dashboard(): void
    {
        $accountant = User::factory()->create(['is_active' => true]);
        $accountant->assignRole('accountant');

        Sanctum::actingAs($accountant);
        $this->getJson('/api/warehouse-dashboard/summary')->assertForbidden();
    }
}
