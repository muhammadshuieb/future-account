<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    /** Keys that must never be returned via the general settings API. */
    public const SECRET_KEYS = [
        'backup_gdrive_credentials_json',
        'backup_gdrive_folder_id',
        'backup_telegram_bot_token',
        'backup_telegram_chat_id',
    ];

    /** Backup destination meta — managed only via /backups APIs. */
    public const BACKUP_DEST_META_KEYS = [
        'backup_gdrive_last_success_at',
        'backup_gdrive_last_error',
        'backup_telegram_last_success_at',
        'backup_telegram_last_error',
    ];

    public static function isHiddenFromSettingsApi(string $key): bool
    {
        return self::isSecretKey($key) || in_array($key, self::BACKUP_DEST_META_KEYS, true);
    }
    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'label',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("setting.{$key}", 3600, function () use ($key) {
            return static::query()->where('key', $key)->value('value');
        });

        return $value ?? $default;
    }

    public static function setValue(string $key, mixed $value, string $group = 'general', string $type = 'string', ?string $label = null): self
    {
        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) || is_object($value) ? json_encode($value) : (string) $value,
                'group' => $group,
                'type' => $type,
                'label' => $label,
            ]
        );

        Cache::forget("setting.{$key}");

        return $setting;
    }

    public static function setEncrypted(string $key, string $plain, string $group = 'backup', ?string $label = null): self
    {
        return static::setValue($key, Crypt::encryptString($plain), $group, 'encrypted', $label);
    }

    public static function getEncrypted(string $key, ?string $default = null): ?string
    {
        $value = static::getValue($key);
        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function forgetKey(string $key): void
    {
        static::query()->where('key', $key)->delete();
        Cache::forget("setting.{$key}");
    }

    public static function isSecretKey(string $key): bool
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    public static function boolValue(string $key, bool $default = false): bool
    {
        $value = static::getValue($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function taxEnabled(): bool
    {
        return static::boolValue('tax_enabled', true);
    }

    public static function defaultTaxRate(): float
    {
        if (! static::taxEnabled()) {
            return 0.0;
        }

        return (float) (static::getValue('tax_rate', 15) ?? 15);
    }

    public static function defaultLocale(): string
    {
        $locale = (string) (static::getValue('default_locale') ?? static::getValue('locale', 'ar') ?? 'ar');

        return in_array($locale, ['ar', 'en', 'tr'], true) ? $locale : 'ar';
    }

    /** @return array{0: string, 1: string} */
    public static function backupTimes(): array
    {
        $t1 = static::normalizeTime((string) (static::getValue('backup_time_1', '02:00') ?? '02:00'), '02:00');
        $t2 = static::normalizeTime((string) (static::getValue('backup_time_2', '14:00') ?? '14:00'), '14:00');

        return [$t1, $t2];
    }

    public static function backupRetentionDays(): int
    {
        $days = (int) (static::getValue('backup_retention_days', 7) ?? 7);

        return max(1, min(365, $days > 0 ? $days : 7));
    }

    public static function backupMinKeep(): int
    {
        $n = (int) (static::getValue('backup_min_keep', 3) ?? 3);

        return max(1, min(50, $n > 0 ? $n : 3));
    }

    public static function normalizeTime(string $value, string $fallback): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return $fallback;
    }
}
