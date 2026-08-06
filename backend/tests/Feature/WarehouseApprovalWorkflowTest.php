<?php

namespace Tests\Feature;

use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $admin;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, ChartOfAccountsSeeder::class]);

        $this->warehouseA = Warehouse::query()->create(['code' => 'A', 'name' => 'Warehouse A', 'is_active' => true]);
        $this->warehouseB = Warehouse::query()->create(['code' => 'B', 'name' => 'Warehouse B', 'is_active' => true]);
        $this->product = Product::query()->create([
            'sku' => 'SKU-A',
            'name' => 'Scoped product',
            'cost_price' => 10,
            'sale_price' => 15,
            'reorder_level' => 2,
            'is_active' => true,
        ]);
        StockLevel::query()->create([
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $this->product->id,
            'batch_no' => '',
            'quantity' => 10,
        ]);

        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->assignRole('warehouse_manager');
        $this->manager->warehouses()->attach($this->warehouseA);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
    }

    public function test_adjustment_stays_pending_and_approval_applies_atomically(): void
    {
        Sanctum::actingAs($this->manager);
        $response = $this->postJson('/api/stock-movements', [
            'type' => 'out',
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'post_to_gl' => false,
        ])->assertStatus(202)->assertJsonPath('data.pending_approval', true);

        $requestId = $response->json('data.approval_request.id');
        $this->assertDatabaseHas('warehouse_approval_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->assertSame(10.0, $this->stock());
        $this->assertSame(0, StockMovement::query()->count());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$requestId}/approve", ['comment' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame(7.0, $this->stock());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'warehouse_approval.requested']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'warehouse_approval.approved']);
    }

    public function test_rejection_never_mutates_operational_state(): void
    {
        Sanctum::actingAs($this->manager);
        $requestId = $this->postJson('/api/stock-movements', [
            'type' => 'in',
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $this->product->id,
            'quantity' => 4,
        ])->assertStatus(202)->json('data.approval_request.id');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$requestId}/reject", ['comment' => 'not justified'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(10.0, $this->stock());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'warehouse_approval.rejected']);
    }

    public function test_warehouse_scope_is_enforced_on_reads_and_writes(): void
    {
        $otherProduct = Product::query()->create([
            'sku' => 'SKU-B',
            'name' => 'Hidden product',
            'cost_price' => 1,
            'sale_price' => 2,
            'is_active' => true,
        ]);
        StockLevel::query()->create([
            'warehouse_id' => $this->warehouseB->id,
            'product_id' => $otherProduct->id,
            'batch_no' => '',
            'quantity' => 5,
        ]);

        Sanctum::actingAs($this->manager);
        $products = $this->getJson('/api/products')->assertOk()->json('data');
        $this->assertTrue(collect($products)->contains('id', $this->product->id));
        $this->assertFalse(collect($products)->contains('id', $otherProduct->id));

        $this->getJson("/api/warehouses/{$this->warehouseB->id}")->assertForbidden();
        $this->postJson('/api/stock-movements', [
            'type' => 'in',
            'warehouse_id' => $this->warehouseB->id,
            'product_id' => $otherProduct->id,
            'quantity' => 1,
        ])->assertForbidden();
    }

    public function test_manager_can_list_only_minimal_active_transfer_target_metadata(): void
    {
        $this->warehouseB->update(['location' => 'Secret aisle', 'notes' => 'Sensitive target notes']);

        Sanctum::actingAs($this->manager);
        $targets = $this->getJson('/api/warehouses/transfer-targets')
            ->assertOk()
            ->json('data');

        $target = collect($targets)->firstWhere('id', $this->warehouseB->id);
        $this->assertSame([
            'id' => $this->warehouseB->id,
            'name' => $this->warehouseB->name,
            'code' => $this->warehouseB->code,
        ], $target);
        $this->getJson("/api/warehouses/{$this->warehouseB->id}")->assertForbidden();
        $this->getJson("/api/stock-levels?warehouse_id={$this->warehouseB->id}")->assertForbidden();
        $this->getJson("/api/products/{$this->product->id}/stock?warehouse_id={$this->warehouseB->id}")
            ->assertForbidden();
    }

    public function test_stale_update_is_not_applied(): void
    {
        Sanctum::actingAs($this->manager);
        $payload = [
            'sku' => $this->product->sku,
            'name' => 'Requested name',
            'cost_price' => 10,
            'sale_price' => 15,
            'reorder_level' => 2,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
        ];
        $requestId = $this->putJson("/api/products/{$this->product->id}", $payload)
            ->assertStatus(202)
            ->json('data.approval_request.id');

        $this->product->update(['name' => 'Concurrent admin edit']);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('conflict');

        $this->assertSame('Concurrent admin edit', $this->product->fresh()->name);
        $this->assertDatabaseHas('warehouse_approval_requests', ['id' => $requestId, 'status' => 'pending']);
    }

    public function test_product_with_opening_stock_is_created_only_after_approval(): void
    {
        Sanctum::actingAs($this->manager);
        $requestId = $this->postJson('/api/products', [
            'sku' => 'NEW-1',
            'name' => 'Pending product',
            'brand' => 'Huawei',
            'model' => 'Nova 12',
            'cost_price' => 6,
            'sale_price' => 8,
            'reorder_level' => 1,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
            'warehouse_id' => $this->warehouseA->id,
            'opening_quantity' => 9,
        ])->assertStatus(202)->json('data.approval_request.id');

        $this->assertDatabaseMissing('products', ['sku' => 'NEW-1']);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/warehouse-approvals?status=pending')
            ->assertOk()
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.items.0.id', $requestId)
            ->assertJsonPath('data.items.0.after_payload.brand', 'Huawei')
            ->assertJsonPath('data.items.0.after_payload.model', 'Nova 12');

        $this->postJson("/api/warehouse-approvals/{$requestId}/approve")->assertOk();

        $created = Product::query()->where('sku', 'NEW-1')->firstOrFail();
        $this->assertSame('Huawei', $created->brand);
        $this->assertSame('Nova 12', $created->model);
        $this->assertSame(9.0, (float) StockLevel::query()
            ->where('warehouse_id', $this->warehouseA->id)
            ->where('product_id', $created->id)
            ->sum('quantity'));
    }

    public function test_product_edit_stays_pending_until_admin_approves_or_rejects(): void
    {
        $originalName = $this->product->name;
        $payload = [
            'sku' => $this->product->sku,
            'name' => 'Proposed rename',
            'cost_price' => 10,
            'sale_price' => 15,
            'reorder_level' => 2,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
        ];

        Sanctum::actingAs($this->manager);
        $approveRequestId = $this->putJson("/api/products/{$this->product->id}", $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.pending_approval', true)
            ->json('data.approval_request.id');

        $this->assertSame($originalName, $this->product->fresh()->name);
        $this->assertDatabaseHas('warehouse_approval_requests', [
            'id' => $approveRequestId,
            'status' => 'pending',
            'action_type' => 'product.update',
        ]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$approveRequestId}/approve")->assertOk();
        $this->assertSame('Proposed rename', $this->product->fresh()->name);

        Sanctum::actingAs($this->manager);
        $rejectPayload = array_merge($payload, ['name' => 'Should never apply']);
        $rejectRequestId = $this->putJson("/api/products/{$this->product->id}", $rejectPayload)
            ->assertStatus(202)
            ->json('data.approval_request.id');
        $this->assertSame('Proposed rename', $this->product->fresh()->name);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$rejectRequestId}/reject", ['comment' => 'keep current name'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->assertSame('Proposed rename', $this->product->fresh()->name);
    }

    public function test_manager_cannot_transfer_from_unauthorized_warehouse(): void
    {
        Sanctum::actingAs($this->manager);
        $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $this->warehouseB->id,
            'to_warehouse_id' => $this->warehouseA->id,
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, WarehouseTransfer::query()->count());
        $this->assertSame(10.0, $this->stock());
    }

    public function test_requester_cannot_self_approve_even_with_review_permission(): void
    {
        Sanctum::actingAs($this->manager);
        $requestId = $this->postJson('/api/stock-movements', [
            'type' => 'in',
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
        ])->assertStatus(202)->json('data.approval_request.id');

        $this->manager->givePermissionTo('warehouse.approvals.review');
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/warehouse-approvals/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('reviewer');
    }

    public function test_posted_transfer_is_not_created_or_applied_before_approval(): void
    {
        Sanctum::actingAs($this->manager);
        $requestId = $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $this->warehouseA->id,
            'to_warehouse_id' => $this->warehouseB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 4]],
        ])->assertStatus(202)->json('data.approval_request.id');

        $this->assertSame(0, WarehouseTransfer::query()->count());
        $this->assertSame(10.0, $this->stock());

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/warehouse-approvals/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.review_context.source_warehouse.id', $this->warehouseA->id)
            ->assertJsonPath('data.review_context.target_warehouse.id', $this->warehouseB->id)
            ->assertJsonPath('data.review_context.lines.0.product.id', $this->product->id)
            ->assertJsonPath('data.review_context.lines.0.quantity', 4)
            ->assertJsonPath('data.review_context.lines.0.source_current_stock', 10);
        $this->postJson("/api/warehouse-approvals/{$requestId}/approve")->assertOk();

        $this->assertDatabaseHas('warehouse_transfers', ['status' => 'posted']);
        $this->assertSame(6.0, $this->stock());
        $this->assertSame(4.0, (float) StockLevel::query()
            ->where('warehouse_id', $this->warehouseB->id)
            ->where('product_id', $this->product->id)
            ->sum('quantity'));
        $this->assertSame(2, StockMovement::query()->where('type', 'transfer')->count());
    }

    public function test_transfer_rejection_leaves_both_warehouses_unchanged(): void
    {
        Sanctum::actingAs($this->manager);
        $requestId = $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $this->warehouseA->id,
            'to_warehouse_id' => $this->warehouseB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 3]],
        ])->assertStatus(202)->json('data.approval_request.id');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$requestId}/reject", ['comment' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(10.0, $this->stock());
        $this->assertSame(0.0, (float) StockLevel::query()
            ->where('warehouse_id', $this->warehouseB->id)
            ->where('product_id', $this->product->id)
            ->sum('quantity'));
        $this->assertSame(0, WarehouseTransfer::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_transfer_approval_refuses_stale_insufficient_source_stock(): void
    {
        Sanctum::actingAs($this->manager);
        $requestId = $this->postJson('/api/warehouse-transfers', [
            'transfer_date' => now()->toDateString(),
            'from_warehouse_id' => $this->warehouseA->id,
            'to_warehouse_id' => $this->warehouseB->id,
            'status' => 'posted',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 8]],
        ])->assertStatus(202)->json('data.approval_request.id');

        StockLevel::query()
            ->where('warehouse_id', $this->warehouseA->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => 2]);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$requestId}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('quantity');

        $this->assertSame(2.0, $this->stock());
        $this->assertSame(0.0, (float) StockLevel::query()
            ->where('warehouse_id', $this->warehouseB->id)
            ->where('product_id', $this->product->id)
            ->sum('quantity'));
        $this->assertDatabaseHas('warehouse_approval_requests', ['id' => $requestId, 'status' => 'pending']);
        $this->assertSame(0, WarehouseTransfer::query()->count());
    }

    public function test_inventory_count_creation_and_posting_each_require_approval(): void
    {
        Sanctum::actingAs($this->manager);
        $createRequestId = $this->postJson('/api/inventory-counts', [
            'warehouse_id' => $this->warehouseA->id,
            'count_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'counted_qty' => 8]],
        ])->assertStatus(202)->json('data.approval_request.id');

        $this->assertSame(0, InventoryCount::query()->count());
        $this->assertSame(10.0, $this->stock());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$createRequestId}/approve")->assertOk();
        $count = InventoryCount::query()->firstOrFail();
        $this->assertSame('draft', $count->status);
        $this->assertSame(10.0, $this->stock());

        Sanctum::actingAs($this->manager);
        $postRequestId = $this->postJson("/api/inventory-counts/{$count->id}/post")
            ->assertStatus(202)
            ->json('data.approval_request.id');
        $this->assertSame(10.0, $this->stock());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/warehouse-approvals/{$postRequestId}/approve")->assertOk();
        $this->assertSame('posted', $count->fresh()->status);
        $this->assertSame(8.0, $this->stock());
    }

    public function test_admin_can_assign_manager_role_and_specific_warehouses(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/users', [
            'first_name' => 'Warehouse',
            'last_name' => 'Manager',
            'username' => 'warehouse_manager',
            'mobile' => '0900000000',
            'password' => 'secure-password',
            'roles' => ['warehouse_manager'],
            'warehouse_ids' => [$this->warehouseA->id, $this->warehouseB->id],
        ])->assertCreated();

        $response->assertJsonPath('data.roles.0', 'warehouse_manager');
        $this->assertEqualsCanonicalizing(
            [$this->warehouseA->id, $this->warehouseB->id],
            $response->json('data.warehouse_ids'),
        );
    }

    protected function stock(): float
    {
        return (float) StockLevel::query()
            ->where('warehouse_id', $this->warehouseA->id)
            ->where('product_id', $this->product->id)
            ->sum('quantity');
    }
}
