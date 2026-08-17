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
            'reference' => 'CUS-AUD',
        ]);
    }

    public function test_audit_log_records_external_ip_behind_proxy(): void
    {
        AuditLog::query()->delete();

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.4'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.50, 127.0.0.1',
                'X-Real-IP' => '203.0.113.50',
            ])
            ->postJson('/api/customers', [
                'code' => 'CUS-IP',
                'name' => 'عميل IP',
                'credit_limit' => 0,
                'is_active' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'customer.created',
            'reference' => 'CUS-IP',
            'ip_address' => '203.0.113.50',
        ]);
    }

    public function test_audit_logs_search_by_document_number_and_arabic_label(): void
    {
        AuditLog::query()->delete();

        AuditLog::query()->create([
            'user_id' => $this->user->id,
            'action' => 'sales_invoice.created',
            'auditable_type' => \App\Models\SalesInvoice::class,
            'auditable_id' => 99,
            'reference' => 'SI-FIND-ME',
            'ip_address' => '203.0.113.10',
        ]);
        AuditLog::query()->create([
            'user_id' => $this->user->id,
            'action' => 'purchase_invoice.updated',
            'auditable_type' => \App\Models\PurchaseInvoice::class,
            'auditable_id' => 88,
            'reference' => 'PI-FIND-ME',
            'ip_address' => '203.0.113.11',
        ]);

        $this->getJson('/api/audit-logs?q=SI-FIND-ME')
            ->assertOk()
            ->assertJsonFragment(['reference' => 'SI-FIND-ME'])
            ->assertJsonMissing(['reference' => 'PI-FIND-ME']);

        $sales = $this->getJson('/api/audit-logs?q='.rawurlencode('مبيع'));
        $sales->assertOk()->assertJsonFragment(['reference' => 'SI-FIND-ME']);

        $purchases = $this->getJson('/api/audit-logs?q='.rawurlencode('شراء'));
        $purchases->assertOk()->assertJsonFragment(['reference' => 'PI-FIND-ME']);
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
                'reference' => 'CUS-API',
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

    public function test_audit_logs_paginate_and_filter_by_period(): void
    {
        AuditLog::query()->delete();

        foreach (range(1, 3) as $i) {
            AuditLog::query()->create([
                'user_id' => $this->user->id,
                'action' => 'sales_invoice.created',
                'auditable_type' => \App\Models\SalesInvoice::class,
                'auditable_id' => $i,
                'reference' => 'ROW-'.$i,
                'ip_address' => '203.0.113.20',
            ]);
        }

        $old = AuditLog::query()->create([
            'user_id' => $this->user->id,
            'action' => 'purchase_invoice.created',
            'auditable_type' => \App\Models\PurchaseInvoice::class,
            'auditable_id' => 9,
            'reference' => 'OLD-ROW',
            'ip_address' => '203.0.113.21',
        ]);
        AuditLog::query()->where('id', $old->id)->update([
            'created_at' => now()->startOfMonth()->subDay(),
        ]);

        $page1 = $this->getJson('/api/audit-logs?period=all&per_page=2&page=1');
        $page1->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertCount(2, $page1->json('data'));

        $page2 = $this->getJson('/api/audit-logs?period=all&per_page=2&page=2');
        $page2->assertOk();
        $this->assertCount(2, $page2->json('data'));

        $month = $this->getJson('/api/audit-logs?period=month&per_page=50');
        $month->assertOk();
        $refs = collect($month->json('data'))->pluck('reference');
        $this->assertTrue($refs->contains('ROW-1'));
        $this->assertFalse($refs->contains('OLD-ROW'));
        $this->assertSame(3, $month->json('meta.total'));
    }
}
