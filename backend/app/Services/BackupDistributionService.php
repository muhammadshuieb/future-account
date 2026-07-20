<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BackupDistributionService
{
    public function status(): array
    {
        return [
            'google_drive' => [
                'configured' => $this->googleDriveConfigured(),
                'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID') ? '***' : null,
            ],
            'telegram' => [
                'configured' => $this->telegramConfigured(),
                'chat_id' => env('TELEGRAM_CHAT_ID') ? '***' : null,
            ],
        ];
    }

    /**
     * @return array{google_drive: array<string, mixed>, telegram: array<string, mixed>}
     */
    public function distribute(string $path, string $filename): array
    {
        $results = [
            'google_drive' => ['ok' => false, 'skipped' => true],
            'telegram' => ['ok' => false, 'skipped' => true],
        ];

        if ($this->googleDriveConfigured()) {
            try {
                $fileId = $this->uploadToGoogleDrive($path, $filename);
                $results['google_drive'] = ['ok' => true, 'file_id' => $fileId];
            } catch (\Throwable $e) {
                Log::error('Google Drive backup upload failed', ['error' => $e->getMessage(), 'file' => $filename]);
                $results['google_drive'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        if ($this->telegramConfigured()) {
            try {
                $messageId = $this->sendToTelegram($path, $filename);
                $results['telegram'] = ['ok' => true, 'message_id' => $messageId];
            } catch (\Throwable $e) {
                Log::error('Telegram backup upload failed', ['error' => $e->getMessage(), 'file' => $filename]);
                $results['telegram'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function googleDriveConfigured(): bool
    {
        return filled(env('GOOGLE_DRIVE_CREDENTIALS_JSON')) && filled(env('GOOGLE_DRIVE_FOLDER_ID'));
    }

    public function telegramConfigured(): bool
    {
        return filled(env('TELEGRAM_BOT_TOKEN')) && filled(env('TELEGRAM_CHAT_ID'));
    }

    protected function uploadToGoogleDrive(string $path, string $filename): string
    {
        $token = $this->googleAccessToken();
        $folderId = env('GOOGLE_DRIVE_FOLDER_ID');
        $mime = 'application/octet-stream';
        $metadata = json_encode([
            'name' => $filename,
            'parents' => [$folderId],
        ], JSON_THROW_ON_ERROR);

        $boundary = 'syna_backup_'.bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .$metadata."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$mime}\r\n\r\n"
            .file_get_contents($path)."\r\n"
            ."--{$boundary}--";

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => "multipart/related; boundary={$boundary}"])
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive API error: '.$response->body());
        }

        return (string) $response->json('id');
    }

    protected function sendToTelegram(string $path, string $filename): int
    {
        $token = env('TELEGRAM_BOT_TOKEN');
        $chatId = env('TELEGRAM_CHAT_ID');

        $response = Http::attach('document', file_get_contents($path), $filename)
            ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                'chat_id' => $chatId,
                'caption' => 'Syna Co backup — '.now()->format('Y-m-d H:i'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram API error: '.$response->body());
        }

        return (int) data_get($response->json(), 'result.message_id', 0);
    }

    protected function googleAccessToken(): string
    {
        $json = env('GOOGLE_DRIVE_CREDENTIALS_JSON');
        if (! $json) {
            throw new RuntimeException('GOOGLE_DRIVE_CREDENTIALS_JSON is not set.');
        }

        $credentials = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claim = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));

        $unsigned = "{$header}.{$claim}";
        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('Invalid Google service account private key.');
        }

        openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google OAuth token error: '.$response->body());
        }

        return (string) $response->json('access_token');
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
