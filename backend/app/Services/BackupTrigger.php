<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Coalesces "backup after invoice" requests for the minute scheduler.
 * Multiple invoices in the same minute produce one Telegram backup.
 */
class BackupTrigger
{
    public const CACHE_KEY = 'syna.backup.after_invoice';

    public static function requestAfterInvoice(string $reason = 'invoice'): void
    {
        try {
            Cache::put(self::CACHE_KEY, [
                'reason' => $reason,
                'at' => now()->toIso8601String(),
            ], now()->addHours(6));
        } catch (\Throwable $e) {
            Log::warning('Could not queue backup-after-invoice flag: '.$e->getMessage(), [
                'reason' => $reason,
            ]);
        }
    }

    public static function isPending(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }

    public static function consume(): ?array
    {
        $payload = Cache::pull(self::CACHE_KEY);

        return is_array($payload) ? $payload : null;
    }
}
