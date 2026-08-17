<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsernameAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_with_username_and_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.username', 'admin')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'first_name', 'last_name', 'mobile']]);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);
    }

    public function test_admin_can_create_user_with_profile_fields(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users', [
            'first_name' => 'أحمد',
            'last_name' => 'علي',
            'username' => 'ahmad_ali',
            'mobile' => '0944123456',
            'password' => 'password123',
            'roles' => ['accountant'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.username', 'ahmad_ali')
            ->assertJsonPath('data.first_name', 'أحمد')
            ->assertJsonPath('data.last_name', 'علي')
            ->assertJsonPath('data.mobile', '0944123456')
            ->assertJsonPath('data.name', 'أحمد علي');

        $this->assertDatabaseHas('users', [
            'username' => 'ahmad_ali',
            'first_name' => 'أحمد',
            'last_name' => 'علي',
            'mobile' => '0944123456',
            'name' => 'أحمد علي',
        ]);

        $this->postJson('/api/auth/login', [
            'username' => 'ahmad_ali',
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.username', 'ahmad_ali');
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name' => 'سامي',
            'last_name' => 'محمد',
            'username' => 'sami_m',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();
    }

    public function test_web_login_token_does_not_expire(): void
    {
        $this->seed(AdminUserSeeder::class);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
        ]);
        $response->assertOk();
        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $row = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        $this->assertNotNull($row);
        $this->assertNull($row->expires_at);
        $this->assertSame('web', $row->name);
    }

    public function test_android_login_token_expires_in_90_days(): void
    {
        $this->seed(AdminUserSeeder::class);

        $response = $this->postJson('/api/auth/login', [
            'username' => 'admin',
            'password' => 'password',
            'client' => 'android',
            'device_name' => 'Pixel',
        ]);
        $response->assertOk();
        $token = $response->json('token');
        $row = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
        $this->assertNotNull($row);
        $this->assertSame('Pixel', $row->name);
        $this->assertNotNull($row->expires_at);
        $this->assertTrue($row->expires_at->between(now()->addDays(89), now()->addDays(91)));
    }

    public function test_warehouse_user_cannot_update_settings(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('warehouse');
        Sanctum::actingAs($user);

        $this->putJson('/api/settings', [
            'settings' => [['key' => 'tax_rate', 'value' => '99']],
        ])->assertForbidden();
    }

    public function test_admin_can_update_settings(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->putJson('/api/settings', [
            'settings' => [['key' => 'tax_rate', 'value' => '10']],
        ])->assertOk();
    }
}
