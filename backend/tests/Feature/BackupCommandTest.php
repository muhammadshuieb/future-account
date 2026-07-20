<?php

namespace Tests\Feature;

use App\Services\BackupDistributionService;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_command_runs_without_external_credentials(): void
    {
        putenv('GOOGLE_DRIVE_CREDENTIALS_JSON=');
        putenv('GOOGLE_DRIVE_FOLDER_ID=');
        putenv('TELEGRAM_BOT_TOKEN=');
        putenv('TELEGRAM_CHAT_ID=');

        $backup = Mockery::mock(BackupService::class);
        $backup->shouldReceive('create')
            ->once()
            ->with('auto')
            ->andReturn([
                'filename' => 'future_account_auto_test.dump',
                'path' => storage_path('app/backups/future_account_auto_test.dump'),
            ]);

        $dist = Mockery::mock(BackupDistributionService::class);
        $dist->shouldReceive('distribute')
            ->once()
            ->andReturn([
                'google_drive' => ['ok' => false, 'skipped' => true],
                'telegram' => ['ok' => false, 'skipped' => true],
            ]);

        $this->app->instance(BackupService::class, $backup);
        $this->app->instance(BackupDistributionService::class, $dist);

        Artisan::call('syna:backup');

        $this->assertStringContainsString('future_account_auto_test.dump', Artisan::output());
    }

    public function test_distribution_skips_when_telegram_not_configured(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=');
        putenv('TELEGRAM_CHAT_ID=');

        $service = new BackupDistributionService;
        $status = $service->status();

        $this->assertFalse($status['telegram']['configured']);
    }

    public function test_telegram_upload_uses_bot_api_when_configured(): void
    {
        putenv('TELEGRAM_BOT_TOKEN=test-token');
        putenv('TELEGRAM_CHAT_ID=12345');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
        ]);

        $path = storage_path('app/backups/test_telegram.dump');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, 'test backup content');

        $service = new BackupDistributionService;
        $results = $service->distribute($path, 'test_telegram.dump');

        $this->assertTrue($results['telegram']['ok']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bottest-token/sendDocument'));
    }
}
