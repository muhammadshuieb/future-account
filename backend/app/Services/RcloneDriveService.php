<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class RcloneDriveService
{
    public const REMOTE_NAME = 'syna_gdrive';

    /**
     * @param  array<string, mixed>  $token
     *
     * @throws RuntimeException
     */
    public function uploadFile(string $localPath, string $remoteFilename, string $folderId, array $token): void
    {
        if (! is_file($localPath)) {
            throw new RuntimeException('ملف النسخة الاحتياطية غير موجود.');
        }

        $configPath = $this->writeConfig($token, $folderId);

        try {
            $process = new Process([
                $this->binary(),
                'copyto',
                $localPath,
                self::REMOTE_NAME.':'.$remoteFilename,
                '--config', $configPath,
                '--drive-root-folder-id', $folderId,
            ]);
            $process->setTimeout(900);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->safeOutput($process->getErrorOutput() ?: $process->getOutput()));
            }
        } finally {
            if (is_file($configPath)) {
                @unlink($configPath);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $token
     *
     * @throws RuntimeException
     */
    public function deleteFile(string $remoteFilename, string $folderId, array $token): void
    {
        $configPath = $this->writeConfig($token, $folderId);

        try {
            $process = new Process([
                $this->binary(),
                'delete',
                self::REMOTE_NAME.':'.$remoteFilename,
                '--config', $configPath,
                '--drive-root-folder-id', $folderId,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('rclone delete failed', [
                    'file' => $remoteFilename,
                    'error' => $this->safeOutput($process->getErrorOutput() ?: $process->getOutput()),
                ]);
            }
        } finally {
            if (is_file($configPath)) {
                @unlink($configPath);
            }
        }
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary(), 'version']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  array<string, mixed>  $token
     */
    protected function writeConfig(array $token, string $folderId): string
    {
        $tokenForRclone = [
            'access_token' => (string) ($token['access_token'] ?? ''),
            'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'expiry' => (string) ($token['expiry'] ?? now()->addHour()->toIso8601String()),
        ];

        $tokenJson = json_encode($tokenForRclone, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ini = "[{$this->remoteSection()}]\n"
            ."type = drive\n"
            ."scope = drive.file\n"
            ."token = {$tokenJson}\n"
            ."root_folder_id = {$folderId}\n";

        $path = storage_path('app/rclone-'.bin2hex(random_bytes(8)).'.conf');
        file_put_contents($path, $ini);
        chmod($path, 0600);

        return $path;
    }

    protected function remoteSection(): string
    {
        return self::REMOTE_NAME;
    }

    protected function binary(): string
    {
        return (string) (env('RCLONE_BINARY') ?: 'rclone');
    }

    protected function safeOutput(string $output): string
    {
        $safe = preg_replace('/"access_token"\s*:\s*"[^"]*"/', '"access_token":"[REDACTED]"', $output) ?? $output;
        $safe = preg_replace('/"refresh_token"\s*:\s*"[^"]*"/', '"refresh_token":"[REDACTED]"', $safe) ?? $safe;

        return mb_substr(trim($safe), 0, 500);
    }
}
