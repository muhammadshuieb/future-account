<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupService
{
    public function directory(): string
    {
        $dir = env('BACKUP_PATH', storage_path('app/backups'));
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        return $dir;
    }

    public function list(): array
    {
        $files = collect(File::files($this->directory()))
            ->filter(fn ($f) => Str::endsWith($f->getFilename(), ['.dump', '.sql', '.backup']))
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
        $path = $this->pathFor($filename);
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

        // pg_restore may return non-zero with warnings; fail only on hard errors / missing file
        if (! $process->isSuccessful() && ! File::exists($path)) {
            throw new RuntimeException('فشل الاستعادة: '.$process->getErrorOutput());
        }

        $err = $process->getErrorOutput();
        if (! $process->isSuccessful() && str_contains(strtolower($err), 'fatal')) {
            throw new RuntimeException('فشل الاستعادة: '.$err);
        }
    }

    public function delete(string $filename): void
    {
        File::delete($this->pathFor($filename));
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

        // Windows / local fallbacks
        foreach (['pg_dump', 'pg_restore'] as $bin) {
            // no-op for discovery
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
