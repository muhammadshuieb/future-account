<?php

namespace App\Services;

use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

class BackupService
{
    public const ALLOWED_EXTENSIONS = ['sql', 'dump', 'backup', 'gz'];

    /** Max upload size in kilobytes (512 MB). */
    public const MAX_UPLOAD_KB = 524288;

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
            ->filter(fn ($f) => Str::endsWith($f->getFilename(), ['.dump', '.sql', '.backup', '.gz']))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();

        return $files->map(fn ($f) => [
            'filename' => $f->getFilename(),
            'size' => $f->getSize(),
            'size_human' => $this->humanSize($f->getSize()),
            'created_at' => date('c', $f->getMTime()),
        ])->all();
    }

    public function create(?string $label = null): array
    {
        $stamp = now()->format('Ymd_His');
        $safe = $label ? Str::slug($label, '_') : 'manual';
        $filename = "future_account_{$safe}_{$stamp}.dump";
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

        return [
            'filename' => $filename,
            'size' => File::size($path),
            'size_human' => $this->humanSize(File::size($path)),
            'created_at' => now()->toIso8601String(),
            'path' => $path,
        ];
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
        File::delete($this->pathFor($filename));
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
