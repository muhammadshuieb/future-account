<?php

namespace App\Support;

use App\Models\Product;

class ProductSku
{
    /**
     * Auto-generate next SKU: PRD-00001, PRD-00002, …
     * Call inside a DB transaction so lockForUpdate is effective.
     */
    public static function next(): string
    {
        $prefix = 'PRD-';
        $last = Product::query()
            ->where('sku', 'like', $prefix.'%')
            ->orderByDesc('sku')
            ->lockForUpdate()
            ->value('sku');

        $seq = 1;
        if (is_string($last) && preg_match('/^PRD-(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        } else {
            // Fallback: highest numeric suffix among PRD-* skus that may not sort lexicographically for mixed padding
            $candidates = Product::query()
                ->where('sku', 'like', $prefix.'%')
                ->pluck('sku');
            foreach ($candidates as $sku) {
                if (preg_match('/^PRD-(\d+)$/', (string) $sku, $m)) {
                    $seq = max($seq, ((int) $m[1]) + 1);
                }
            }
        }

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
