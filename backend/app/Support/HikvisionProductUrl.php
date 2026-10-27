<?php

namespace App\Support;

/**
 * Builds a Hikvision product/datasheet search URL from a catalog model number.
 * Admin may override with an exact datasheet PDF URL on the price-list item.
 */
class HikvisionProductUrl
{
    public static function forModel(?string $model): ?string
    {
        $query = self::cleanModel($model);
        if ($query === '') {
            return null;
        }

        // Official site search — returns the product page / datasheet for the model.
        return 'https://www.hikvision.com/en/search/?q='.rawurlencode($query);
    }

    public static function cleanModel(?string $model): string
    {
        if ($model === null) {
            return '';
        }

        $s = trim($model);
        if ($s === '') {
            return '';
        }

        // Drop optical / regional suffixes in parentheses: (2.8mm), (O-STD), (eF), …
        $s = preg_replace('/\([^)]*\)/u', '', $s) ?? $s;
        // Normalize whitespace and trailing separators
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B/-");

        return $s;
    }
}
