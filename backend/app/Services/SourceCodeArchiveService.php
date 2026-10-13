<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Builds / locates the retained source-code zip (app + install guide).
 * Prefer SOURCE_ARCHIVE_ROOT (host mount or deploy-copied tree); otherwise reuse LATEST zip.
 */
class SourceCodeArchiveService
{
    public const LATEST_FILENAME = '03_كود-البرمجة-وطريقة-التثبيت_SynaAcc.zip';

    public const TELEGRAM_CAPTION = 'كود البرمجة الكامل مع طريقة التثبيت — SynaAcc';

    /** @var list<string> */
    protected array $excludeDirNames = [
        '.git',
        'node_modules',
        'vendor',
        'dist',
        'coverage',
        '.idea',
        '.vscode',
        '.cursor',
        'storage',
        'bootstrap/cache',
        'agent-transcripts',
        'terminals',
    ];

    /** @var list<string> */
    protected array $excludeFileGlobs = [
        '.env',
        '.env.prod',
        '.env.local',
        '*.dump',
        '*.sql.gz',
        'company-stamp.tmp.png',
    ];

    public function latestFilename(): string
    {
        return self::LATEST_FILENAME;
    }

    public function latestPath(): string
    {
        return rtrim(app(BackupService::class)->directory(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .self::LATEST_FILENAME;
    }

    public function exists(): bool
    {
        $path = $this->latestPath();

        return is_file($path) && filesize($path) > 1024;
    }

    /**
     * @return array{filename: string, path: string, size: int, size_human: string, built: bool}
     */
    public function ensureLatest(?string $sourceRoot = null): array
    {
        $root = $this->resolveSourceRoot($sourceRoot);
        if ($root !== null) {
            return $this->buildFromRoot($root);
        }

        if ($this->exists()) {
            $path = $this->latestPath();

            return [
                'filename' => self::LATEST_FILENAME,
                'path' => $path,
                'size' => (int) filesize($path),
                'size_human' => $this->humanSize((int) filesize($path)),
                'built' => false,
            ];
        }

        throw new RuntimeException(
            'أرشيف الكود غير موجود. شغّل scripts/build-source-archive.sh أثناء النشر أو عيّن SOURCE_ARCHIVE_ROOT.'
        );
    }

    /**
     * @return array{filename: string, path: string, size: int, size_human: string, built: bool}
     */
    public function buildFromRoot(string $root): array
    {
        $root = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
        if (! is_dir($root)) {
            throw new RuntimeException('مجلد المصدر غير موجود: '.$root);
        }

        $dest = $this->latestPath();
        $dir = dirname($dest);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $tmp = $dest.'.tmp.'.getmypid();
        if (is_file($tmp)) {
            @unlink($tmp);
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذّر إنشاء أرشيف ZIP للكود.');
        }

        $installSrc = $root.DIRECTORY_SEPARATOR.'docs'.DIRECTORY_SEPARATOR.'INSTALL-AR.md';
        if (is_file($installSrc)) {
            $zip->addFile($installSrc, 'INSTALL-AR.md');
        }

        $this->addDirectory($zip, $root, $root, 'SynaAcc');
        $zip->close();

        if (! is_file($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);
            throw new RuntimeException('أرشيف الكود الناتج فارغ أو صغير جداً.');
        }

        if (is_file($dest)) {
            @unlink($dest);
        }
        if (! @rename($tmp, $dest)) {
            File::copy($tmp, $dest);
            @unlink($tmp);
        }

        $size = (int) filesize($dest);

        return [
            'filename' => self::LATEST_FILENAME,
            'path' => $dest,
            'size' => $size,
            'size_human' => $this->humanSize($size),
            'built' => true,
        ];
    }

    protected function resolveSourceRoot(?string $override): ?string
    {
        if ($override && is_dir($override)) {
            return $override;
        }

        $fromEnv = env('SOURCE_ARCHIVE_ROOT');
        if (is_string($fromEnv) && $fromEnv !== '' && is_dir($fromEnv)) {
            return $fromEnv;
        }

        // When running on the host outside Docker (e.g. local artisan).
        $repoGuess = base_path('..');
        if (is_file($repoGuess.DIRECTORY_SEPARATOR.'docker-compose.yml')
            && is_dir($repoGuess.DIRECTORY_SEPARATOR.'backend')
            && is_dir($repoGuess.DIRECTORY_SEPARATOR.'frontend')) {
            return $repoGuess;
        }

        return null;
    }

    protected function addDirectory(ZipArchive $zip, string $absDir, string $root, string $zipPrefix): void
    {
        $items = @scandir($absDir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $abs = $absDir.DIRECTORY_SEPARATOR.$item;
            $relFromRoot = ltrim(str_replace($root, '', $abs), DIRECTORY_SEPARATOR);
            $relFromRoot = str_replace('\\', '/', $relFromRoot);

            if ($this->shouldExclude($item, $relFromRoot, is_dir($abs))) {
                continue;
            }

            $zipPath = $zipPrefix.'/'.$relFromRoot;

            if (is_dir($abs)) {
                $zip->addEmptyDir($zipPath);
                $this->addDirectory($zip, $abs, $root, $zipPrefix);

                continue;
            }

            if (! is_file($abs) || ! is_readable($abs)) {
                continue;
            }

            $zip->addFile($abs, $zipPath);
        }
    }

    protected function shouldExclude(string $name, string $relPath, bool $isDir): bool
    {
        $rel = str_replace('\\', '/', $relPath);
        $parts = explode('/', $rel);

        if ($isDir) {
            if (in_array($name, $this->excludeDirNames, true)) {
                return true;
            }
            foreach ($parts as $part) {
                if (in_array($part, ['.git', 'node_modules', 'vendor', 'dist'], true)) {
                    return true;
                }
            }
            if (str_starts_with($rel, 'backend/storage')) {
                return true;
            }
            if (str_starts_with($rel, 'scripts/_tmp')) {
                return true;
            }

            return false;
        }

        foreach ($this->excludeFileGlobs as $glob) {
            if ($this->matchGlob($name, $glob) || $this->matchGlob(basename($rel), $glob)) {
                return true;
            }
        }

        if (str_starts_with($name, '_tmp-') || str_contains($rel, '/_tmp-')) {
            return true;
        }

        if (preg_match('/\.(dump|log|sqlite)$/i', $name)) {
            return true;
        }

        return false;
    }

    protected function matchGlob(string $name, string $glob): bool
    {
        return fnmatch($glob, $name);
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 2).' '.$units[$i];
    }
}
