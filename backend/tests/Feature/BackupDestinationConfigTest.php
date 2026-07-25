<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\BackupDistributionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BackupDestinationConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        putenv('GOOGLE_DRIVE_CREDENTIALS_JSON=');
        putenv('GOOGLE_DRIVE_FOLDER_ID=');
        putenv('TELEGRAM_BOT_TOKEN=');
        putenv('TELEGRAM_CHAT_ID=');
        $_ENV['GOOGLE_DRIVE_CREDENTIALS_JSON'] = '';
        $_ENV['GOOGLE_DRIVE_FOLDER_ID'] = '';
        $_ENV['TELEGRAM_BOT_TOKEN'] = '';
        $_ENV['TELEGRAM_CHAT_ID'] = '';
    }

    protected function actingAsAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        return $user;
    }

    protected function sampleServiceAccountJson(): string
    {
        return json_encode([
            'type' => 'service_account',
            'project_id' => 'syna-test',
            'private_key_id' => 'abc',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7TESTKEYFORUNIT\n-----END PRIVATE KEY-----\n",
            'client_email' => 'syna-backup@example.iam.gserviceaccount.com',
            'client_id' => '123',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR);
    }

    public function test_non_admin_cannot_save_telegram(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('sales');
        Sanctum::actingAs($user);

        $this->putJson('/api/backups/destinations/telegram', [
            'bot_token' => '123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'chat_id' => '12345',
        ])->assertForbidden();
    }

    public function test_admin_can_save_telegram_and_status_masks_secrets(): void
    {
        $this->actingAsAdmin();

        $token = '123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $chatId = '-1001234567890';

        $save = $this->putJson('/api/backups/destinations/telegram', [
            'bot_token' => $token,
            'chat_id' => $chatId,
        ])->assertOk();

        $save->assertJsonPath('data.telegram.configured', true);
        $save->assertJsonPath('data.telegram.status', 'connected');
        $save->assertJsonMissing(['bot_token' => $token]);
        $this->assertStringNotContainsString($token, $save->getContent());
        $this->assertStringNotContainsString($chatId, $save->getContent());

        $status = $this->getJson('/api/backups/status')->assertOk();
        $status->assertJsonPath('data.telegram.configured', true);
        $status->assertJsonPath('data.telegram.token_set', true);
        $status->assertJsonPath('data.telegram.chat_id_set', true);
        $this->assertStringNotContainsString($token, $status->getContent());
        $this->assertStringNotContainsString($chatId, $status->getContent());

        // Encrypted in DB — plaintext must not appear in settings table value as-is.
        $row = Setting::query()->where('key', BackupDistributionService::KEY_TELEGRAM_TOKEN)->first();
        $this->assertNotNull($row);
        $this->assertNotSame($token, $row->value);
        $this->assertSame($token, Setting::getEncrypted(BackupDistributionService::KEY_TELEGRAM_TOKEN));

        // General settings API must not expose secret keys.
        $settings = $this->getJson('/api/settings')->assertOk();
        $keys = collect($settings->json('data'))->pluck('key');
        $this->assertFalse($keys->contains(BackupDistributionService::KEY_TELEGRAM_TOKEN));
    }

    public function test_admin_can_disconnect_telegram(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/backups/destinations/telegram', [
            'bot_token' => '123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'chat_id' => '42',
        ])->assertOk();

        $this->deleteJson('/api/backups/destinations/telegram')
            ->assertOk()
            ->assertJsonPath('data.telegram.configured', false)
            ->assertJsonPath('data.telegram.status', 'disconnected');

        $this->assertNull(Setting::getEncrypted(BackupDistributionService::KEY_TELEGRAM_TOKEN));
    }

    public function test_admin_can_save_google_drive_and_mask_credentials(): void
    {
        $this->actingAsAdmin();
        $json = $this->sampleServiceAccountJson();
        $folderId = '1AbCdEfGhIjKlMnOpQrStUvWxYz';

        $save = $this->putJson('/api/backups/destinations/google-drive', [
            'credentials_json' => $json,
            'folder_id' => $folderId,
        ])->assertOk();

        $save->assertJsonPath('data.google_drive.configured', true);
        $save->assertJsonPath('data.google_drive.credentials_set', true);
        $this->assertStringNotContainsString($folderId, $save->getContent());
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $save->getContent());
        $this->assertStringNotContainsString('syna-backup@example', $save->getContent());

        $status = $this->getJson('/api/backups/status')->assertOk();
        $status->assertJsonPath('data.google_drive.configured', true);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $status->getContent());
    }

    public function test_telegram_test_sends_message_when_mocked(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/backups/destinations/telegram', [
            'bot_token' => '123456789:AAHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'chat_id' => '999',
        ])->assertOk();

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]], 200),
        ]);

        $this->postJson('/api/backups/destinations/telegram/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) $request['text'], 'اختبار اتصال');
        });
    }

    public function test_distribution_prefers_encrypted_settings_over_env(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=env-token-should-not-win:xxxxxxxxxxxxxxxxxxxx');
        putenv('TELEGRAM_CHAT_ID=111');
        $_ENV['TELEGRAM_BOT_TOKEN'] = 'env-token-should-not-win:xxxxxxxxxxxxxxxxxxxx';
        $_ENV['TELEGRAM_CHAT_ID'] = '111';

        Setting::setEncrypted(
            BackupDistributionService::KEY_TELEGRAM_TOKEN,
            '123456789:AAHsettings-token-xxxxxxxxxxxx',
            'backup',
        );
        Setting::setEncrypted(
            BackupDistributionService::KEY_TELEGRAM_CHAT,
            '222',
            'backup',
        );

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);

        $path = storage_path('app/backups/test_pref.dump');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'x');

        $service = new BackupDistributionService;
        $results = $service->distribute($path, 'test_pref.dump');

        $this->assertTrue($results['telegram']['ok']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'bot123456789:AAHsettings-token-xxxxxxxxxxxx/sendDocument'));
    }

    public function test_invalid_telegram_token_rejected(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/backups/destinations/telegram', [
            'bot_token' => 'short',
            'chat_id' => '1',
        ])->assertStatus(422);
    }
}
