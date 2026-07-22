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

    public function test_guest_cannot_restore_upload(): void
    {
        $this->post('/api/backups/restore-upload', [
            'confirm' => '1',
        ])->assertUnauthorized();
    }

    public function test_non_admin_cannot_restore_upload(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('sales');
        Sanctum::actingAs($user);

        $this->post('/api/backups/restore-upload', [
            'confirm' => '1',
        ])->assertForbidden();
    }

    public function test_admin_restore_upload_rejects_bad_extension(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $file = \Illuminate\Http\UploadedFile::fake()->create('malware.exe', 10);

        $this->post('/api/backups/restore-upload', [
            'file' => $file,
            'confirm' => '1',
        ], [
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }
}
