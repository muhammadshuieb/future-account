<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BackupDistributionService
{
    public const KEY_GDRIVE_CREDENTIALS = 'backup_gdrive_credentials_json';

    public const KEY_GDRIVE_FOLDER = 'backup_gdrive_folder_id';

    public const KEY_TELEGRAM_TOKEN = 'backup_telegram_bot_token';

    public const KEY_TELEGRAM_CHAT = 'backup_telegram_chat_id';

    public const KEY_GDRIVE_LAST_OK = 'backup_gdrive_last_success_at';

    public const KEY_GDRIVE_LAST_ERR = 'backup_gdrive_last_error';

    public const KEY_TELEGRAM_LAST_OK = 'backup_telegram_last_success_at';

    public const KEY_TELEGRAM_LAST_ERR = 'backup_telegram_last_error';

    public function status(): array
    {
        return [
            'google_drive' => $this->googleDriveStatus(),
            'telegram' => $this->telegramStatus(),
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
                $this->recordSuccess('google_drive');
            } catch (\Throwable $e) {
                $safe = $this->safeErrorMessage($e->getMessage());
                Log::error('Google Drive backup upload failed', ['error' => $safe, 'file' => $filename]);
                $this->recordError('google_drive', $safe);
                $results['google_drive'] = ['ok' => false, 'error' => $safe];
            }
        }

        if ($this->telegramConfigured()) {
            try {
                $messageId = $this->sendToTelegram($path, $filename);
                $results['telegram'] = ['ok' => true, 'message_id' => $messageId];
                $this->recordSuccess('telegram');
            } catch (\Throwable $e) {
                $safe = $this->safeErrorMessage($e->getMessage());
                Log::error('Telegram backup upload failed', ['error' => $safe, 'file' => $filename]);
                $this->recordError('telegram', $safe);
                $results['telegram'] = ['ok' => false, 'error' => $safe];
            }
        }

        return $results;
    }

    public function googleDriveConfigured(): bool
    {
        return filled($this->googleDriveCredentialsJson()) && filled($this->googleDriveFolderId());
    }

    public function telegramConfigured(): bool
    {
        return filled($this->telegramBotToken()) && filled($this->telegramChatId());
    }

    /**
     * @return array{configured: bool, status: string, source: string, credentials_set: bool, folder_id_set: bool, folder_id_masked: ?string, last_success_at: ?string, last_error: ?string}
     */
    public function googleDriveStatus(): array
    {
        $configured = $this->googleDriveConfigured();
        $credsFromSettings = filled(Setting::getEncrypted(self::KEY_GDRIVE_CREDENTIALS));
        $folderFromSettings = filled(Setting::getEncrypted(self::KEY_GDRIVE_FOLDER));
        $source = 'none';
        if ($credsFromSettings || $folderFromSettings) {
            $source = 'settings';
        } elseif (filled(env('GOOGLE_DRIVE_CREDENTIALS_JSON')) || filled(env('GOOGLE_DRIVE_FOLDER_ID'))) {
            $source = 'env';
        }

        $lastOk = Setting::getValue(self::KEY_GDRIVE_LAST_OK);
        $lastErr = Setting::getValue(self::KEY_GDRIVE_LAST_ERR);
        $folder = $this->googleDriveFolderId();

        return [
            'configured' => $configured,
            'status' => $this->connectionState($configured, is_string($lastErr) ? $lastErr : null),
            'source' => $source,
            'credentials_set' => filled($this->googleDriveCredentialsJson()),
            'folder_id_set' => filled($folder),
            'folder_id_masked' => $this->maskId($folder),
            'last_success_at' => is_string($lastOk) && $lastOk !== '' ? $lastOk : null,
            'last_error' => is_string($lastErr) && $lastErr !== '' ? $lastErr : null,
        ];
    }

    /**
     * @return array{configured: bool, status: string, source: string, token_set: bool, chat_id_set: bool, chat_id_masked: ?string, last_success_at: ?string, last_error: ?string}
     */
    public function telegramStatus(): array
    {
        $configured = $this->telegramConfigured();
        $tokenFromSettings = filled(Setting::getEncrypted(self::KEY_TELEGRAM_TOKEN));
        $chatFromSettings = filled(Setting::getEncrypted(self::KEY_TELEGRAM_CHAT));
        $source = 'none';
        if ($tokenFromSettings || $chatFromSettings) {
            $source = 'settings';
        } elseif (filled(env('TELEGRAM_BOT_TOKEN')) || filled(env('TELEGRAM_CHAT_ID'))) {
            $source = 'env';
        }

        $lastOk = Setting::getValue(self::KEY_TELEGRAM_LAST_OK);
        $lastErr = Setting::getValue(self::KEY_TELEGRAM_LAST_ERR);
        $chat = $this->telegramChatId();

        return [
            'configured' => $configured,
            'status' => $this->connectionState($configured, is_string($lastErr) ? $lastErr : null),
            'source' => $source,
            'token_set' => filled($this->telegramBotToken()),
            'chat_id_set' => filled($chat),
            'chat_id_masked' => $this->maskId($chat),
            'last_success_at' => is_string($lastOk) && $lastOk !== '' ? $lastOk : null,
            'last_error' => is_string($lastErr) && $lastErr !== '' ? $lastErr : null,
        ];
    }

    /**
     * @throws RuntimeException
     */
    public function saveGoogleDrive(?string $credentialsJson, ?string $folderId): array
    {
        if ($credentialsJson !== null && trim($credentialsJson) !== '') {
            $this->assertValidServiceAccountJson($credentialsJson);
            Setting::setEncrypted(
                self::KEY_GDRIVE_CREDENTIALS,
                trim($credentialsJson),
                'backup',
                'Google Drive service account JSON',
            );
        }

        if ($folderId !== null && trim($folderId) !== '') {
            Setting::setEncrypted(
                self::KEY_GDRIVE_FOLDER,
                trim($folderId),
                'backup',
                'Google Drive folder ID',
            );
        }

        if (! $this->googleDriveConfigured()) {
            throw new RuntimeException('يجب إدخال ملف بيانات حساب الخدمة ومعرّف المجلد معاً (أو إكمال الناقص).');
        }

        Setting::forgetKey(self::KEY_GDRIVE_LAST_ERR);

        return $this->googleDriveStatus();
    }

    /**
     * @throws RuntimeException
     */
    public function saveTelegram(?string $botToken, ?string $chatId): array
    {
        if ($botToken !== null && trim($botToken) !== '') {
            $token = trim($botToken);
            if (strlen($token) < 20 || ! str_contains($token, ':')) {
                throw new RuntimeException('رمز بوت تيليجرام غير صالح.');
            }
            Setting::setEncrypted(
                self::KEY_TELEGRAM_TOKEN,
                $token,
                'backup',
                'Telegram bot token',
            );
        }

        if ($chatId !== null && trim($chatId) !== '') {
            $chat = trim($chatId);
            if (! preg_match('/^-?\d+$/', $chat)) {
                throw new RuntimeException('معرّف محادثة تيليجرام يجب أن يكون رقماً.');
            }
            Setting::setEncrypted(
                self::KEY_TELEGRAM_CHAT,
                $chat,
                'backup',
                'Telegram chat ID',
            );
        }

        if (! $this->telegramConfigured()) {
            throw new RuntimeException('يجب إدخال رمز البوت ومعرّف المحادثة معاً (أو إكمال الناقص).');
        }

        Setting::forgetKey(self::KEY_TELEGRAM_LAST_ERR);

        return $this->telegramStatus();
    }

    public function disconnectGoogleDrive(): array
    {
        Setting::forgetKey(self::KEY_GDRIVE_CREDENTIALS);
        Setting::forgetKey(self::KEY_GDRIVE_FOLDER);
        Setting::forgetKey(self::KEY_GDRIVE_LAST_ERR);
        Setting::forgetKey(self::KEY_GDRIVE_LAST_OK);

        return $this->googleDriveStatus();
    }

    public function disconnectTelegram(): array
    {
        Setting::forgetKey(self::KEY_TELEGRAM_TOKEN);
        Setting::forgetKey(self::KEY_TELEGRAM_CHAT);
        Setting::forgetKey(self::KEY_TELEGRAM_LAST_ERR);
        Setting::forgetKey(self::KEY_TELEGRAM_LAST_OK);

        return $this->telegramStatus();
    }

    /**
     * Upload then delete a tiny test file in the configured Drive folder.
     *
     * @throws RuntimeException
     */
    public function testGoogleDrive(): array
    {
        if (! $this->googleDriveConfigured()) {
            throw new RuntimeException('Google Drive غير مُعد. احفظ الإعدادات أولاً.');
        }

        $token = $this->googleAccessToken();
        $folderId = $this->googleDriveFolderId();
        $filename = 'syna_connection_test_'.now()->format('YmdHis').'.txt';
        $content = 'Syna Co backup connection test — '.now()->toIso8601String();

        try {
            $fileId = $this->uploadDriveBytes($token, $folderId, $filename, $content, 'text/plain');
            $this->deleteDriveFile($token, $fileId);
            $this->recordSuccess('google_drive');

            return array_merge($this->googleDriveStatus(), [
                'ok' => true,
                'message' => 'تم الاتصال بنجاح مع Google Drive.',
            ]);
        } catch (\Throwable $e) {
            $safe = $this->safeErrorMessage($e->getMessage());
            $this->recordError('google_drive', $safe);
            throw new RuntimeException('فشل اختبار Google Drive: '.$safe);
        }
    }

    /**
     * Send a small Arabic test message to the configured chat.
     *
     * @throws RuntimeException
     */
    public function testTelegram(): array
    {
        if (! $this->telegramConfigured()) {
            throw new RuntimeException('تيليجرام غير مُعد. احفظ الإعدادات أولاً.');
        }

        $token = $this->telegramBotToken();
        $chatId = $this->telegramChatId();

        $response = Http::asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => 'اختبار اتصال النسخ الاحتياطي — Syna Co ✓'."\n".now()->format('Y-m-d H:i'),
        ]);

        if (! $response->successful() || ! data_get($response->json(), 'ok')) {
            $safe = $this->safeErrorMessage((string) $response->body());
            $this->recordError('telegram', $safe);
            throw new RuntimeException('فشل اختبار تيليجرام: '.$safe);
        }

        $this->recordSuccess('telegram');

        return array_merge($this->telegramStatus(), [
            'ok' => true,
            'message' => 'تم إرسال رسالة الاختبار بنجاح.',
            'message_id' => (int) data_get($response->json(), 'result.message_id', 0),
        ]);
    }

    protected function googleDriveCredentialsJson(): ?string
    {
        $fromSettings = Setting::getEncrypted(self::KEY_GDRIVE_CREDENTIALS);
        if (filled($fromSettings)) {
            return $fromSettings;
        }

        $fromEnv = env('GOOGLE_DRIVE_CREDENTIALS_JSON');

        return filled($fromEnv) ? (string) $fromEnv : null;
    }

    protected function googleDriveFolderId(): ?string
    {
        $fromSettings = Setting::getEncrypted(self::KEY_GDRIVE_FOLDER);
        if (filled($fromSettings)) {
            return $fromSettings;
        }

        $fromEnv = env('GOOGLE_DRIVE_FOLDER_ID');

        return filled($fromEnv) ? (string) $fromEnv : null;
    }

    protected function telegramBotToken(): ?string
    {
        $fromSettings = Setting::getEncrypted(self::KEY_TELEGRAM_TOKEN);
        if (filled($fromSettings)) {
            return $fromSettings;
        }

        $fromEnv = env('TELEGRAM_BOT_TOKEN');

        return filled($fromEnv) ? (string) $fromEnv : null;
    }

    protected function telegramChatId(): ?string
    {
        $fromSettings = Setting::getEncrypted(self::KEY_TELEGRAM_CHAT);
        if (filled($fromSettings)) {
            return $fromSettings;
        }

        $fromEnv = env('TELEGRAM_CHAT_ID');

        return filled($fromEnv) ? (string) $fromEnv : null;
    }

    protected function uploadToGoogleDrive(string $path, string $filename): string
    {
        $token = $this->googleAccessToken();
        $folderId = $this->googleDriveFolderId();
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('تعذر قراءة ملف النسخة الاحتياطية.');
        }

        return $this->uploadDriveBytes($token, $folderId, $filename, $bytes, 'application/octet-stream');
    }

    protected function uploadDriveBytes(string $token, ?string $folderId, string $filename, string $bytes, string $mime): string
    {
        if (! filled($folderId)) {
            throw new RuntimeException('معرّف مجلد Google Drive غير موجود.');
        }

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
            .$bytes."\r\n"
            ."--{$boundary}--";

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => "multipart/related; boundary={$boundary}"])
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id');

        if (! $response->successful()) {
            throw new RuntimeException('Google Drive API error: '.$this->safeErrorMessage($response->body()));
        }

        return (string) $response->json('id');
    }

    protected function deleteDriveFile(string $token, string $fileId): void
    {
        $response = Http::withToken($token)
            ->delete('https://www.googleapis.com/drive/v3/files/'.$fileId);

        if (! $response->successful()) {
            Log::warning('Google Drive test file cleanup failed', [
                'file_id' => $fileId,
                'error' => $this->safeErrorMessage($response->body()),
            ]);
        }
    }

    protected function sendToTelegram(string $path, string $filename): int
    {
        $token = $this->telegramBotToken();
        $chatId = $this->telegramChatId();

        $response = Http::attach('document', file_get_contents($path), $filename)
            ->post("https://api.telegram.org/bot{$token}/sendDocument", [
                'chat_id' => $chatId,
                'caption' => 'Syna Co backup — '.now()->format('Y-m-d H:i'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram API error: '.$this->safeErrorMessage($response->body()));
        }

        return (int) data_get($response->json(), 'result.message_id', 0);
    }

    protected function googleAccessToken(): string
    {
        $json = $this->googleDriveCredentialsJson();
        if (! $json) {
            throw new RuntimeException('بيانات حساب خدمة Google Drive غير موجودة.');
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
            throw new RuntimeException('مفتاح حساب خدمة Google غير صالح.');
        }

        openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $unsigned.'.'.$this->base64UrlEncode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google OAuth token error: '.$this->safeErrorMessage($response->body()));
        }

        return (string) $response->json('access_token');
    }

    protected function assertValidServiceAccountJson(string $json): void
    {
        try {
            $credentials = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('ملف JSON لحساب الخدمة غير صالح.');
        }

        if (! is_array($credentials)
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])
            || ($credentials['type'] ?? '') !== 'service_account'
        ) {
            throw new RuntimeException('يجب أن يكون JSON من نوع service_account ويحتوي على client_email و private_key.');
        }
    }

    protected function recordSuccess(string $destination): void
    {
        $at = now()->toIso8601String();
        if ($destination === 'google_drive') {
            Setting::setValue(self::KEY_GDRIVE_LAST_OK, $at, 'backup', 'string', 'آخر رفع ناجح لـ Drive');
            Setting::forgetKey(self::KEY_GDRIVE_LAST_ERR);
        } else {
            Setting::setValue(self::KEY_TELEGRAM_LAST_OK, $at, 'backup', 'string', 'آخر رفع ناجح لـ Telegram');
            Setting::forgetKey(self::KEY_TELEGRAM_LAST_ERR);
        }
    }

    protected function recordError(string $destination, string $message): void
    {
        $safe = $this->safeErrorMessage($message);
        if ($destination === 'google_drive') {
            Setting::setValue(self::KEY_GDRIVE_LAST_ERR, $safe, 'backup', 'string', 'آخر خطأ Drive');
        } else {
            Setting::setValue(self::KEY_TELEGRAM_LAST_ERR, $safe, 'backup', 'string', 'آخر خطأ Telegram');
        }
    }

    protected function connectionState(bool $configured, ?string $lastErr): string
    {
        if (! $configured) {
            return 'disconnected';
        }

        if (filled($lastErr)) {
            return 'error';
        }

        return 'connected';
    }

    protected function safeErrorMessage(string $message): string
    {
        $safe = preg_replace('/bot[^\/\s]+/i', 'bot***', $message) ?? $message;
        $safe = preg_replace('/-----BEGIN[^-]+-----.*?-----END[^-]+-----/s', '[REDACTED_KEY]', $safe) ?? $safe;
        $safe = preg_replace('/"private_key"\s*:\s*"[^"]*"/', '"private_key":"[REDACTED]"', $safe) ?? $safe;

        return mb_substr(trim($safe), 0, 500);
    }

    protected function maskId(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        $v = (string) $value;
        if (strlen($v) <= 4) {
            return '****';
        }

        return str_repeat('*', max(4, strlen($v) - 4)).substr($v, -4);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
