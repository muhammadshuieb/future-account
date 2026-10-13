<?php

namespace App\Services;

use App\Models\Setting;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

class BackupService
{
    public const ALLOWED_EXTENSIONS = ['sql', 'dump', 'backup', 'gz'];

    public const LIST_EXTENSIONS = ['sql', 'dump', 'backup', 'gz', 'xlsx', 'zip'];

    /** Clear Telegram / UI labels for the three backup artifacts. */
    public const CAPTION_DB = 'نسخة احتياطية لقاعدة البيانات — SynaAcc';

    public const CAPTION_EXCEL = 'ملف Excel ببيانات النظام — SynaAcc';

    public const DUMP_PREFIX = '01_نسخة-احتياطية-قاعدة-بيانات_SynaAcc_';

    public const EXCEL_PREFIX = '02_بيانات-Excel_SynaAcc_';

    /** Max upload size in kilobytes (512 MB). */
    public const MAX_UPLOAD_KB = 524288;

    /** Minimum acceptable size for a "fresh" backup proof (bytes). */
    public const MIN_FRESH_BYTES = 1024;

    /** A backup must be newer than this many hours to count as fresh. */
    public const FRESH_MAX_AGE_HOURS = 24;

    public const DEFAULT_RETENTION_DAYS = 7;

    public const DEFAULT_MIN_KEEP = 3;

    public const SKIP_MESSAGE_AR = 'لم يتم حذف النسخ القديمة — لا توجد نسخة احتياطية حديثة! تحقق من نظام النسخ';

    public function directory(): string
    {
        $dir = env('BACKUP_PATH', storage_path('app/backups'));
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    public function uploadDirectory(): string
    {
        $dir = storage_path('app/backup-uploads');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    public function list(): array
    {
        $files = collect(File::files($this->directory()))
            ->filter(fn ($f) => Str::endsWith($f->getFilename(), ['.dump', '.sql', '.backup', '.gz', '.xlsx', '.zip']))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        return $files->map(function ($f) {
            $name = $f->getFilename();
            $lower = strtolower($name);

            return [
                'filename' => $name,
                'size' => $f->getSize(),
                'size_human' => $this->humanSize($f->getSize()),
                'created_at' => date('c', $f->getMTime()),
                'kind' => match (true) {
                    Str::endsWith($lower, '.xlsx') => 'excel',
                    Str::endsWith($lower, '.zip') => 'source',
                    default => 'sql',
                },
                'label_ar' => $this->labelForFilename($name),
            ];
        })->all();
    }

    public function create(?string $label = null): array
    {
        $stamp = now()->format('Ymd_His');
        $safe = $label ? Str::slug($label, '_') : 'manual';
        $filename = self::DUMP_PREFIX."{$safe}_{$stamp}.dump";
        $excelFilename = self::EXCEL_PREFIX."{$safe}_{$stamp}.xlsx";
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;

        $env = $this->pgEnv();
        $process = new Process([
            $this->binary('pg_dump'),
            '-h', $env['host'],
            '-p', $env['port'],
            '-U', $env['user'],
            '-d', $env['database'],
            '-Fc',
            '-f', $path,
        ], null, ['PGPASSWORD' => $env['password']]);

        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('فشل إنشاء النسخة الاحتياطية: '.$process->getErrorOutput());
        }

        $meta = [
            'filename' => $filename,
            'size' => File::size($path),
            'size_human' => $this->humanSize(File::size($path)),
            'created_at' => now()->toIso8601String(),
            'path' => $path,
            'kind' => 'sql',
            'caption' => self::CAPTION_DB.' — '.now()->format('Y-m-d H:i'),
            'label_ar' => 'نسخة احتياطية لقاعدة البيانات',
        ];

        try {
            $excel = app(ExcelExportService::class)->saveFullArchiveBeside(
                $filename,
                $this->directory(),
                $excelFilename,
            );
            $meta['excel_filename'] = $excel['filename'];
            $meta['excel_path'] = $excel['path'];
            $meta['excel_size'] = $excel['size'];
            $meta['excel_size_human'] = $excel['size_human'];
            $meta['excel_caption'] = self::CAPTION_EXCEL.' — '.now()->format('Y-m-d H:i');
            $meta['excel_label_ar'] = 'ملف Excel ببيانات النظام';
        } catch (\Throwable $e) {
            Log::warning('Backup Excel companion failed: '.$e->getMessage(), [
                'dump' => $filename,
            ]);
            $meta['excel_error'] = $e->getMessage();
        }

        try {
            $code = app(SourceCodeArchiveService::class)->ensureLatest();
            $meta['code_filename'] = $code['filename'];
            $meta['code_path'] = $code['path'];
            $meta['code_size'] = $code['size'];
            $meta['code_size_human'] = $code['size_human'];
            $meta['code_caption'] = SourceCodeArchiveService::TELEGRAM_CAPTION.' — '.now()->format('Y-m-d H:i');
            $meta['code_label_ar'] = 'كود البرمجة مع طريقة التثبيت';
        } catch (\Throwable $e) {
            Log::warning('Backup source archive missing: '.$e->getMessage(), [
                'dump' => $filename,
            ]);
            $meta['code_error'] = $e->getMessage();
        }

        return $meta;
    }

    public function labelForFilename(string $filename): string
    {
        $name = basename($filename);
        $lower = strtolower($name);
        if (Str::endsWith($lower, '.zip') || Str::startsWith($name, '03_كود')) {
            return 'كود البرمجة مع طريقة التثبيت';
        }
        if (Str::endsWith($lower, '.xlsx') || Str::startsWith($name, self::EXCEL_PREFIX)) {
            return 'ملف Excel ببيانات النظام';
        }
        if (Str::endsWith($lower, ['.dump', '.sql', '.backup', '.gz']) || Str::startsWith($name, self::DUMP_PREFIX)) {
            return 'نسخة احتياطية لقاعدة البيانات';
        }

        return $name;
    }

    public function excelCompanionPath(string $dumpFilename): string
    {
        $base = pathinfo(basename($dumpFilename), PATHINFO_FILENAME);
        if (Str::startsWith($base, '01_نسخة-احتياطية-قاعدة-بيانات_SynaAcc_')) {
            $excelBase = str_replace(
                '01_نسخة-احتياطية-قاعدة-بيانات_SynaAcc_',
                '02_بيانات-Excel_SynaAcc_',
                $base,
            );

            return $this->directory().DIRECTORY_SEPARATOR.$excelBase.'.xlsx';
        }

        return $this->directory().DIRECTORY_SEPARATOR.$base.'.xlsx';
    }

    public function pathFor(string $filename): string
    {
        $filename = basename($filename);
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;
        if (! File::exists($path)) {
            throw new RuntimeException('الملف غير موجود.');
        }

        return $path;
    }

    public function restore(string $filename): void
    {
        $this->restoreFromPath($this->pathFor($filename));
    }

    /**
     * Restore from an absolute file path (server backup or uploaded file).
     */
    public function restoreFromPath(string $path, bool $ensureAdmin = true): void
    {
        if (! File::exists($path)) {
            throw new RuntimeException('الملف غير موجود.');
        }

        $workPath = $path;
        $cleanup = [];

        try {
            $lower = strtolower($path);
            if (Str::endsWith($lower, '.gz')) {
                $workPath = $this->gunzipToTemp($path);
                $cleanup[] = $workPath;
            }

            if ($this->isCustomFormat($workPath)) {
                $this->runPgRestore($workPath);
            } else {
                $this->runPsqlRestore($workPath);
            }

            $this->afterRestore($ensureAdmin);
        } finally {
            foreach ($cleanup as $tmp) {
                if (is_string($tmp) && File::exists($tmp)) {
                    File::delete($tmp);
                }
            }
        }
    }

    public function isAllowedUploadName(string $filename): bool
    {
        $name = strtolower(basename($filename));

        return (bool) preg_match('/\.(sql|dump|backup)(\.gz)?$/', $name)
            || (bool) preg_match('/\.gz$/', $name);
    }

    public function delete(string $filename): void
    {
        $path = $this->pathFor($filename);
        File::delete($path);

        // When removing a SQL dump, also remove the companion Excel archive.
        // Never delete the retained source-code zip.
        $lower = strtolower(basename($filename));
        if (Str::endsWith($lower, ['.dump', '.sql', '.backup', '.gz'])) {
            $excelCompanion = $this->excelCompanionPath($filename);
            if (File::exists($excelCompanion)) {
                File::delete($excelCompanion);
            }
        }
    }

    public function isRetainedFilename(string $filename): bool
    {
        $name = basename($filename);

        return $name === SourceCodeArchiveService::LATEST_FILENAME
            || Str::startsWith($name, '03_كود-البرمجة');
    }

    /**
     * Delete local backups older than retention days, with safety guards:
     * - require a fresh (≤24h), non-empty backup before any prune
     * - always keep the newest N backups regardless of age
     *
     * Remote copies (Drive/Telegram) are never touched.
     *
     * @return array{
     *   pruned: bool,
     *   skipped: bool,
     *   reason: ?string,
     *   deleted: list<string>,
     *   deleted_count: int,
     *   remaining: int,
     *   retention_days: int,
     *   min_keep: int,
     *   message: string,
     *   at: string
     * }
     */
    public function pruneOldBackups(?int $retentionDays = null, ?int $minKeep = null): array
    {
        $retentionDays = max(1, $retentionDays ?? Setting::backupRetentionDays());
        $minKeep = max(1, $minKeep ?? Setting::backupMinKeep());
        $files = $this->backupFiles();
        $remaining = count($files);
        $at = now()->toIso8601String();

        if (! $this->hasFreshBackup($files)) {
            $result = [
                'pruned' => false,
                'skipped' => true,
                'reason' => 'no_fresh_backup',
                'deleted' => [],
                'deleted_count' => 0,
                'remaining' => $remaining,
                'retention_days' => $retentionDays,
                'min_keep' => $minKeep,
                'message' => self::SKIP_MESSAGE_AR,
                'at' => $at,
            ];
            $this->persistCleanupResult($result);
            Log::warning(self::SKIP_MESSAGE_AR, $result);
            app(AppNotificationService::class)->notifyAdmins(
                'backup_retention_skipped',
                'تحذير: تنظيف النسخ الاحتياطية',
                self::SKIP_MESSAGE_AR,
                $result,
            );

            return $result;
        }

        $cutoff = now()->subDays($retentionDays)->getTimestamp();
        $protected = array_slice($files, 0, $minKeep);
        $protectedNames = array_map(fn ($f) => $f->getFilename(), $protected);

        $deleted = [];
        foreach ($files as $file) {
            $name = $file->getFilename();
            if ($this->isRetainedFilename($name)) {
                continue;
            }
            if (in_array($name, $protectedNames, true)) {
                continue;
            }
            if ($file->getMTime() >= $cutoff) {
                continue;
            }
            File::delete($file->getPathname());
            $deleted[] = $name;

            $excelCompanion = $this->excelCompanionPath($name);
            if (File::exists($excelCompanion) && ! $this->isRetainedFilename(basename($excelCompanion))) {
                File::delete($excelCompanion);
                $deleted[] = basename($excelCompanion);
            }
        }

        $remainingAfter = count($this->backupFiles());
        $result = [
            'pruned' => $deleted !== [],
            'skipped' => false,
            'reason' => null,
            'deleted' => $deleted,
            'deleted_count' => count($deleted),
            'remaining' => $remainingAfter,
            'retention_days' => $retentionDays,
            'min_keep' => $minKeep,
            'message' => $deleted === []
                ? 'لا توجد نسخ قديمة للحذف.'
                : 'تم حذف '.count($deleted).' نسخة قديمة. المتبقي: '.$remainingAfter,
            'at' => $at,
        ];
        $this->persistCleanupResult($result);
        if ($deleted !== []) {
            Log::info('Backup retention pruned old local files', $result);
        }

        return $result;
    }

    public function lastCleanupResult(): ?array
    {
        $raw = Setting::getValue('backup_last_cleanup');
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function retentionStatus(): array
    {
        return [
            'retention_days' => Setting::backupRetentionDays(),
            'min_keep' => Setting::backupMinKeep(),
            'fresh_max_age_hours' => self::FRESH_MAX_AGE_HOURS,
            'min_fresh_bytes' => self::MIN_FRESH_BYTES,
            'has_fresh_backup' => $this->hasFreshBackup($this->backupFiles()),
            'local_count' => count($this->backupFiles()),
            'last_cleanup' => $this->lastCleanupResult(),
            'policy_ar' => 'تُحذف النسخ المحلية الأقدم من '.Setting::backupRetentionDays().' أيام تلقائياً بعد كل نسخ ناجح، بشرط وجود نسخة حديثة (أقل من 24 ساعة) وصحيحة، مع الإبقاء دائماً على أحدث '.Setting::backupMinKeep().' نسخ.',
        ];
    }

    /** @return list<\SplFileInfo> newest first */
    protected function backupFiles(): array
    {
        return collect(File::files($this->directory()))
            ->filter(fn ($f) => Str::endsWith($f->getFilename(), ['.dump', '.sql', '.backup', '.gz']))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values()
            ->all();
    }

    /** @param  list<\SplFileInfo>  $files */
    protected function hasFreshBackup(array $files): bool
    {
        $freshCutoff = now()->subHours(self::FRESH_MAX_AGE_HOURS)->getTimestamp();

        foreach ($files as $file) {
            if ($file->getMTime() >= $freshCutoff && $file->getSize() > self::MIN_FRESH_BYTES) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $result */
    protected function persistCleanupResult(array $result): void
    {
        Setting::setValue(
            'backup_last_cleanup',
            json_encode($result, JSON_UNESCAPED_UNICODE),
            'backup',
            'json',
            'آخر نتيجة لتنظيف النسخ',
        );
    }

    protected function afterRestore(bool $ensureAdmin): void
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
        } catch (\Throwable) {
            // Best-effort; restore itself already succeeded.
        }

        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            //
        }

        if ($ensureAdmin) {
            try {
                Artisan::call('db:seed', [
                    '--class' => AdminUserSeeder::class,
                    '--force' => true,
                ]);
            } catch (\Throwable) {
                // Admin may already exist in the restored dump.
            }
        }
    }

    protected function isCustomFormat(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $magic = fread($handle, 5);
        fclose($handle);

        return $magic === 'PGDMP';
    }

    protected function gunzipToTemp(string $gzPath): string
    {
        $dest = $this->uploadDirectory().DIRECTORY_SEPARATOR.'restore_'.uniqid('', true).'.tmp';
        $in = @gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('تعذر فك ضغط الملف (.gz).');
        }
        $out = fopen($dest, 'wb');
        if ($out === false) {
            gzclose($in);
            throw new RuntimeException('تعذر إنشاء ملف مؤقت للاستعادة.');
        }
        while (! gzeof($in)) {
            $chunk = gzread($in, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);

        return $dest;
    }

    protected function runPgRestore(string $path): void
    {
        $env = $this->pgEnv();

        $process = new Process([
            $this->binary('pg_restore'),
            '-h', $env['host'],
            '-p', $env['port'],
            '-U', $env['user'],
            '-d', $env['database'],
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-acl',
            $path,
        ], null, ['PGPASSWORD' => $env['password']]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful() && ! File::exists($path)) {
            throw new RuntimeException('فشل الاستعادة: '.$process->getErrorOutput());
        }

        $err = $process->getErrorOutput();
        if (! $process->isSuccessful() && str_contains(strtolower($err), 'fatal')) {
            throw new RuntimeException('فشل الاستعادة: '.$err);
        }
    }

    protected function runPsqlRestore(string $path): void
    {
        $env = $this->pgEnv();

        $process = new Process([
            $this->binary('psql'),
            '-h', $env['host'],
            '-p', $env['port'],
            '-U', $env['user'],
            '-d', $env['database'],
            '-v', 'ON_ERROR_STOP=1',
            '-f', $path,
        ], null, ['PGPASSWORD' => $env['password']]);

        $process->setTimeout(600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('فشل الاستعادة: '.$process->getErrorOutput());
        }
    }

    protected function pgEnv(): array
    {
        return [
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => (string) env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'future_account'),
            'user' => env('DB_USERNAME', 'future'),
            'password' => env('DB_PASSWORD', 'secret'),
        ];
    }

    protected function binary(string $name): string
    {
        $which = trim((string) shell_exec('command -v '.escapeshellarg($name).' 2>/dev/null'));
        if ($which !== '') {
            return $which;
        }

        return $name;
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2).' '.$units[$i];
    }
}
