<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->user->assignRole('admin');
        Sanctum::actingAs($this->user);
    }

    public function test_creating_customer_writes_audit_log(): void
    {
        $response = $this->postJson('/api/customers', [
            'code' => 'CUS-AUD',
            'name' => 'عميل تدقيق',
            'credit_limit' => 0,
            'is_active' => true,
        ]);

        $response->assertCreated();

        $customer = Customer::query()->where('code', 'CUS-AUD')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'customer.created',
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
        ]);
    }

    public function test_updating_customer_writes_audit_log(): void
    {
        $customer = Customer::query()->create([
            'code' => 'CUS-UPD',
            'name' => 'قبل التعديل',
            'credit_limit' => 0,
            'is_active' => true,
        ]);

        // Creating above happens while authenticated → clear noise.
        AuditLog::query()->delete();

        $response = $this->putJson("/api/customers/{$customer->id}", [
            'code' => 'CUS-UPD',
            'name' => 'بعد التعديل',
            'credit_limit' => 100,
            'is_active' => true,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'customer.updated',
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
        ]);
    }

    public function test_audit_logs_api_returns_entity_fields(): void
    {
        $customer = Customer::query()->create([
            'code' => 'CUS-API',
            'name' => 'عميل API',
            'credit_limit' => 0,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/audit-logs');

        $response->assertOk()
            ->assertJsonFragment([
                'action' => 'customer.created',
                'entity_type' => 'customer',
                'entity_id' => $customer->id,
            ]);
    }

    public function test_deleting_customer_writes_audit_log(): void
    {
        $customer = Customer::query()->create([
            'code' => 'CUS-DEL',
            'name' => 'للحذف',
            'credit_limit' => 0,
            'is_active' => true,
        ]);

        AuditLog::query()->delete();

        $this->deleteJson("/api/customers/{$customer->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->user->id,
            'action' => 'customer.deleted',
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->id,
        ]);
    }
}
