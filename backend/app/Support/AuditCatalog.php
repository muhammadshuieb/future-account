<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditCatalog
{
    /** Preferred keys for the human-searchable document / record number. */
    public const REFERENCE_KEYS = [
        'invoice_number',
        'quote_number',
        'order_number',
        'request_number',
        'return_number',
        'receipt_number',
        'payment_number',
        'entry_number',
        'transfer_number',
        'exchange_number',
        'count_number',
        'movement_number',
        'employee_number',
        'sku',
        'code',
        'username',
        'name',
        'key',
    ];

    /**
     * Search aliases (Arabic / English / Turkish) → fragments stored on audit_logs.
     *
     * @var array<string, list<string>>
     */
    public const SEARCH_ALIASES = [
        'شراء' => ['purchase_invoice', 'purchase_order', 'purchase_request', 'purchase_return'],
        'مشتريات' => ['purchase_invoice', 'purchase_order', 'purchase_request'],
        'مبيع' => ['sales_invoice', 'sales_order'],
        'مبيعات' => ['sales_invoice', 'sales_order'],
        'فاتورة' => ['sales_invoice', 'purchase_invoice'],
        'عرض سعر' => ['sales_quote'],
        'عرض' => ['sales_quote'],
        'سند قبض' => ['receipt'],
        'قبض' => ['receipt'],
        'سند دفع' => ['supplier_payment'],
        'دفع' => ['supplier_payment'],
        'قيد' => ['journal_entry'],
        'يومية' => ['journal_entry'],
        'صرف' => ['currency_exchange'],
        'تحويل' => ['cash_transfer', 'warehouse_transfer'],
        'نقل' => ['warehouse_transfer'],
        'صنف' => ['product'],
        'عميل' => ['customer'],
        'مورد' => ['supplier'],
        'مستودع' => ['warehouse', 'warehouse_transfer'],
        'صندوق' => ['cash_box'],
        'بنك' => ['bank'],
        'موظف' => ['employee'],
        'إنشاء' => ['.created'],
        'تعديل' => ['.updated'],
        'حذف' => ['.deleted'],
        'ترحيل' => ['.posted'],
        'sale' => ['sales_invoice'],
        'purchase' => ['purchase_invoice'],
        'satış' => ['sales_invoice'],
        'alış' => ['purchase_invoice'],
    ];

    public static function referenceFrom(?Model $model, ?array $old = null, ?array $new = null): ?string
    {
        $bags = [];
        if (is_array($new)) {
            $bags[] = $new;
        }
        if ($model) {
            $bags[] = $model->getAttributes();
            $bags[] = $model->getOriginal();
        }
        if (is_array($old)) {
            $bags[] = $old;
        }

        return self::referenceFromArrays(...$bags);
    }

    public static function referenceFromArrays(?array ...$bags): ?string
    {
        foreach ($bags as $bag) {
            if (! is_array($bag)) {
                continue;
            }
            foreach (self::REFERENCE_KEYS as $key) {
                if (! array_key_exists($key, $bag) || $bag[$key] === null) {
                    continue;
                }
                $value = trim((string) $bag[$key]);
                if ($value !== '' && $value !== '0') {
                    return mb_substr($value, 0, 160);
                }
            }
        }

        return null;
    }

    /**
     * Extra LIKE fragments so Arabic words like "شراء" match stored entity keys.
     *
     * @return list<string>
     */
    public static function searchFragments(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $lower = mb_strtolower($term);
        $fragments = [];

        foreach (self::SEARCH_ALIASES as $alias => $targets) {
            $aliasLower = mb_strtolower($alias);
            if ($aliasLower === $lower || str_contains($lower, $aliasLower) || str_contains($aliasLower, $lower)) {
                foreach ($targets as $target) {
                    $fragments[] = $target;
                }
            }
        }

        return array_values(array_unique($fragments));
    }

    public static function clientIp(?Request $request = null): ?string
    {
        $request ??= request();
        if (! $request) {
            return null;
        }

        $candidates = [];

        $forwarded = $request->headers->get('X-Forwarded-For');
        if (is_string($forwarded) && $forwarded !== '') {
            foreach (explode(',', $forwarded) as $part) {
                $candidates[] = $part;
            }
        }

        $candidates[] = $request->headers->get('X-Real-IP');
        $candidates[] = $request->ip();

        $public = null;
        $fallback = null;

        foreach ($candidates as $raw) {
            $ip = self::normalizeIp((string) $raw);
            if ($ip === null) {
                continue;
            }
            $fallback ??= $ip;
            if (! self::isPrivateOrReserved($ip)) {
                $public ??= $ip;
            }
        }

        return $public ?? $fallback;
    }

    protected static function normalizeIp(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Strip optional port / quotes from proxy headers.
        $value = trim($value, "\"'");
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            $value = $end !== false ? substr($value, 1, $end - 1) : $value;
        } elseif (substr_count($value, ':') === 1) {
            $value = explode(':', $value, 2)[0];
        }

        return filter_var($value, FILTER_VALIDATE_IP) ?: null;
    }

    protected static function isPrivateOrReserved(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
