<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Setting;
use App\Services\BackupService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->backupDir = storage_path('app/backups-test-'.uniqid());
        File::makeDirectory($this->backupDir, 0755, true);
        putenv('BACKUP_PATH='.$this->backupDir);
        $_ENV['BACKUP_PATH'] = $this->backupDir;
        $_SERVER['BACKUP_PATH'] = $this->backupDir;

        Setting::setValue('backup_retention_days', '7', 'backup', 'number', 'مدة الاحتفاظ بالنسخ (أيام)');
        Setting::setValue('backup_min_keep', '3', 'backup', 'number', 'الحد الأدنى لعدد النسخ المحتفظ بها');
    }

    protected function tearDown(): void
    {
        if (isset($this->backupDir) && File::isDirectory($this->backupDir)) {
            File::deleteDirectory($this->backupDir);
        }
        putenv('BACKUP_PATH');
        unset($_ENV['BACKUP_PATH'], $_SERVER['BACKUP_PATH']);
        parent::tearDown();
    }

    public function test_prune_deletes_old_when_fresh_exists(): void
    {
        $this->touchBackup('fresh.dump', now()->subHours(1)->getTimestamp(), 4096);
        $this->touchBackup('recent2.dump', now()->subHours(2)->getTimestamp(), 4096);
        $this->touchBackup('recent3.dump', now()->subHours(12)->getTimestamp(), 4096);
        $this->touchBackup('old_a.dump', now()->subDays(10)->getTimestamp(), 4096);
        $this->touchBackup('old_b.dump', now()->subDays(14)->getTimestamp(), 4096);

        $result = app(BackupService::class)->pruneOldBackups(7, 3);

        $this->assertFalse($result['skipped']);
        $this->assertSame(2, $result['deleted_count']);
        $this->assertContains('old_a.dump', $result['deleted']);
        $this->assertContains('old_b.dump', $result['deleted']);
        $this->assertFileExists($this->backupDir.DIRECTORY_SEPARATOR.'fresh.dump');
        $this->assertFileDoesNotExist($this->backupDir.DIRECTORY_SEPARATOR.'old_a.dump');
        $this->assertFileDoesNotExist($this->backupDir.DIRECTORY_SEPARATOR.'old_b.dump');
    }

    public function test_prune_skipped_and_notifies_when_no_fresh_backup(): void
    {
        $this->touchBackup('stale_a.dump', now()->subDays(3)->getTimestamp(), 4096);
        $this->touchBackup('stale_b.dump', now()->subDays(10)->getTimestamp(), 4096);
        $this->touchBackup('stale_c.dump', now()->subDays(14)->getTimestamp(), 4096);
        $this->touchBackup('tiny_fresh.dump', now()->subHours(1)->getTimestamp(), 100);

        $result = app(BackupService::class)->pruneOldBackups(7, 3);

        $this->assertTrue($result['skipped']);
        $this->assertSame(0, $result['deleted_count']);
        $this->assertSame(BackupService::SKIP_MESSAGE_AR, $result['message']);
        $this->assertFileExists($this->backupDir.DIRECTORY_SEPARATOR.'stale_b.dump');
        $this->assertFileExists($this->backupDir.DIRECTORY_SEPARATOR.'stale_c.dump');

        $this->assertTrue(
            AppNotification::query()->where('type', 'backup_retention_skipped')->exists()
        );
    }

    public function test_prune_respects_min_keep(): void
    {
        // 5 files all older than retention — but fresh exists among newest 3.
        $this->touchBackup('keep1.dump', now()->subHours(2)->getTimestamp(), 4096);
        $this->touchBackup('keep2.dump', now()->subDays(8)->getTimestamp(), 4096);
        $this->touchBackup('keep3.dump', now()->subDays(9)->getTimestamp(), 4096);
        $this->touchBackup('old4.dump', now()->subDays(10)->getTimestamp(), 4096);
        $this->touchBackup('old5.dump', now()->subDays(12)->getTimestamp(), 4096);

        $result = app(BackupService::class)->pruneOldBackups(7, 3);

        $this->assertFalse($result['skipped']);
        $this->assertSame(2, $result['deleted_count']);
        $this->assertSame(3, $result['remaining']);
        $this->assertFileExists($this->backupDir.DIRECTORY_SEPARATOR.'keep1.dump');
        $this->assertFileExists($this->backupDir.DIRECTORY_SEPARATOR.'keep2.dump');
        $this->assertFileExists($this->backupDir.DIRECTORY_SEPARATOR.'keep3.dump');
        $this->assertFileDoesNotExist($this->backupDir.DIRECTORY_SEPARATOR.'old4.dump');
        $this->assertFileDoesNotExist($this->backupDir.DIRECTORY_SEPARATOR.'old5.dump');
    }

    private function touchBackup(string $name, int $mtime, int $size): void
    {
        $path = $this->backupDir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, str_repeat('x', $size));
        touch($path, $mtime);
    }
}
