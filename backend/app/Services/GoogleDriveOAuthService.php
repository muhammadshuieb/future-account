<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GoogleDriveOAuthService
{
    public const DEFAULT_BACKUP_FOLDER_NAME = 'ساينا باك اب';

    public const KEY_OAUTH_TOKEN = 'backup_gdrive_oauth_token';

    public const KEY_FOLDER_ID = 'backup_gdrive_folder_id';

    public const KEY_FOLDER_URL = 'backup_gdrive_folder_url';

    public const OAUTH_STATE_PREFIX = 'gdrive_oauth_state:';

    public function oauthClientConfigured(): bool
    {
        return filled(env('GOOGLE_DRIVE_OAUTH_CLIENT_ID'))
            && filled(env('GOOGLE_DRIVE_OAUTH_CLIENT_SECRET'));
    }

    public function isConnected(): bool
    {
        return filled($this->tokenJson());
    }

    public function folderId(): ?string
    {
        $fromSettings = Setting::getEncrypted(self::KEY_FOLDER_ID);
        if (filled($fromSettings)) {
            return (string) $fromSettings;
        }

        $fromEnv = env('GOOGLE_DRIVE_FOLDER_ID');

        return filled($fromEnv) ? (string) $fromEnv : null;
    }

    public function folderUrl(): ?string
    {
        $url = Setting::getValue(self::KEY_FOLDER_URL);

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function fullyConfigured(): bool
    {
        return $this->isConnected() && filled($this->folderId());
    }

    /**
     * Accept a Drive folder URL or a raw folder ID.
     *
     * @throws RuntimeException
     */
    public function parseFolderReference(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new RuntimeException('أدخل رابط مجلد Google Drive.');
        }

        if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $input, $matches)) {
            return $matches[1];
        }

        if (preg_match('#^[a-zA-Z0-9_-]{10,}$#', $input)) {
            return $input;
        }

        throw new RuntimeException('رابط مجلد Google Drive غير صالح. انسخ الرابط من شريط المتصفح.');
    }

    public function saveFolderReference(string $input): array
    {
        if (! $this->isConnected()) {
            throw new RuntimeException('اربط Google Drive أولاً بالضغط على «ربط Google Drive».');
        }

        $folderId = $this->parseFolderReference($input);
        $folderUrl = str_contains($input, '/folders/')
            ? trim($input)
            : 'https://drive.google.com/drive/folders/'.$folderId;

        Setting::setEncrypted(self::KEY_FOLDER_ID, $folderId, 'backup', 'Google Drive folder ID');
        Setting::setValue(self::KEY_FOLDER_URL, $folderUrl, 'backup', 'string', 'Google Drive folder URL');

        return [
            'folder_id' => $folderId,
            'folder_url' => $folderUrl,
        ];
    }

    public function createAuthUrl(int $userId): string
    {
        if (! $this->oauthClientConfigured()) {
            throw new RuntimeException('OAuth Google Drive غير مُعد على الخادم (CLIENT_ID / CLIENT_SECRET).');
        }

        $state = Str::random(48);
        Cache::put(self::OAUTH_STATE_PREFIX.$state, $userId, now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => env('GOOGLE_DRIVE_OAUTH_CLIENT_ID'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * @throws RuntimeException
     */
    public function handleCallback(string $code, string $state): void
    {
        $userId = Cache::pull(self::OAUTH_STATE_PREFIX.$state);
        if (! $userId) {
            throw new RuntimeException('انتهت صلاحية جلسة الربط. أعد المحاولة من الإعدادات.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => env('GOOGLE_DRIVE_OAUTH_CLIENT_ID'),
            'client_secret' => env('GOOGLE_DRIVE_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('فشل تبادل رمز Google: '.$this->safeMessage($response->body()));
        }

        $data = $response->json();
        $refreshToken = $data['refresh_token'] ?? null;
        if (! filled($refreshToken)) {
            throw new RuntimeException('لم يُرجَع refresh_token. افصل التطبيق من حساب Google وأعد الربط.');
        }

        $token = [
            'access_token' => (string) ($data['access_token'] ?? ''),
            'token_type' => (string) ($data['token_type'] ?? 'Bearer'),
            'refresh_token' => (string) $refreshToken,
            'expiry' => now()->addSeconds((int) ($data['expires_in'] ?? 3600))->toIso8601String(),
        ];

        Setting::setEncrypted(
            self::KEY_OAUTH_TOKEN,
            json_encode($token, JSON_THROW_ON_ERROR),
            'backup',
            'Google Drive OAuth token (rclone)',
        );
        Setting::forgetKey(BackupDistributionService::KEY_GDRIVE_LAST_ERR);

        $this->ensureDefaultBackupFolder($token);
    }

    /**
     * Find or create the default backup folder on the connected Drive account.
     *
     * @param  array<string, mixed>  $token
     * @return array{folder_id: string, folder_name: string, folder_url: string}
     */
    public function ensureDefaultBackupFolder(?array $token = null): array
    {
        $token = $token ?? $this->tokenPayload();
        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('رمز Google Drive غير متاح.');
        }

        $name = self::DEFAULT_BACKUP_FOLDER_NAME;
        $search = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/drive/v3/files', [
                'q' => "name='{$name}' and mimeType='application/vnd.google-apps.folder' and trashed=false",
                'fields' => 'files(id,name)',
                'spaces' => 'drive',
            ]);

        $folderId = null;
        if ($search->successful()) {
            $folderId = data_get($search->json(), 'files.0.id');
        }

        if (! filled($folderId)) {
            $create = Http::withToken($accessToken)
                ->post('https://www.googleapis.com/drive/v3/files', [
                    'name' => $name,
                    'mimeType' => 'application/vnd.google-apps.folder',
                ]);

            if (! $create->successful()) {
                throw new RuntimeException('تعذر إنشاء مجلد «'.$name.'»: '.$this->safeMessage($create->body()));
            }

            $folderId = (string) $create->json('id');
        }

        $folderUrl = 'https://drive.google.com/drive/folders/'.$folderId;
        Setting::setEncrypted(self::KEY_FOLDER_ID, $folderId, 'backup', 'Google Drive folder ID');
        Setting::setValue(self::KEY_FOLDER_URL, $folderUrl, 'backup', 'string', 'Google Drive folder URL');

        return [
            'folder_id' => $folderId,
            'folder_name' => $name,
            'folder_url' => $folderUrl,
        ];
    }

    public function defaultFolderName(): string
    {
        return self::DEFAULT_BACKUP_FOLDER_NAME;
    }

    public function disconnect(): void
    {
        Setting::forgetKey(self::KEY_OAUTH_TOKEN);
        Setting::forgetKey(self::KEY_FOLDER_ID);
        Setting::forgetKey(self::KEY_FOLDER_URL);
        Setting::forgetKey(BackupDistributionService::KEY_GDRIVE_LAST_ERR);
        Setting::forgetKey(BackupDistributionService::KEY_GDRIVE_LAST_OK);
    }

    /**
     * @return array<string, mixed>
     */
    public function tokenPayload(): array
    {
        $json = $this->tokenJson();
        if (! $json) {
            throw new RuntimeException('Google Drive غير مربوط.');
        }

        $token = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($token) || empty($token['refresh_token'])) {
            throw new RuntimeException('رمز Google Drive المحفوظ غير صالح. أعد الربط.');
        }

        return $this->ensureFreshToken($token);
    }

    protected function tokenJson(): ?string
    {
        $fromSettings = Setting::getEncrypted(self::KEY_OAUTH_TOKEN);

        return filled($fromSettings) ? (string) $fromSettings : null;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<string, mixed>
     */
    protected function ensureFreshToken(array $token): array
    {
        $expiry = isset($token['expiry']) ? \Carbon\Carbon::parse((string) $token['expiry']) : now()->subMinute();
        if ($expiry->isFuture()) {
            return $token;
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => env('GOOGLE_DRIVE_OAUTH_CLIENT_ID'),
            'client_secret' => env('GOOGLE_DRIVE_OAUTH_CLIENT_SECRET'),
            'refresh_token' => $token['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('فشل تجديد رمز Google Drive: '.$this->safeMessage($response->body()));
        }

        $data = $response->json();
        $token['access_token'] = (string) ($data['access_token'] ?? $token['access_token']);
        $token['token_type'] = (string) ($data['token_type'] ?? 'Bearer');
        $token['expiry'] = now()->addSeconds((int) ($data['expires_in'] ?? 3600))->toIso8601String();

        Setting::setEncrypted(
            self::KEY_OAUTH_TOKEN,
            json_encode($token, JSON_THROW_ON_ERROR),
            'backup',
            'Google Drive OAuth token (rclone)',
        );

        return $token;
    }

    public function redirectUri(): string
    {
        $configured = env('GOOGLE_DRIVE_OAUTH_REDIRECT_URI');

        if (filled($configured)) {
            return (string) $configured;
        }

        $base = rtrim((string) (env('FRONTEND_URL') ?: env('APP_URL') ?: 'http://localhost:8080'), '/');

        return $base.'/api/backups/destinations/google-drive/callback';
    }

    protected function safeMessage(string $message): string
    {
        return mb_substr(trim($message), 0, 400);
    }
}
