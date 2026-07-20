<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_cannot_list_backups(): void
    {
        $this->getJson('/api/backups')->assertUnauthorized();
    }

    public function test_non_admin_cannot_list_backups(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('sales');
        Sanctum::actingAs($user);

        $this->getJson('/api/backups')->assertForbidden();
    }

    public function test_admin_can_list_backups(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $this->getJson('/api/backups')->assertOk()->assertJsonStructure(['data']);
    }
}
