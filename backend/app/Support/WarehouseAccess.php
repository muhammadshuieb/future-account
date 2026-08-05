<?php

namespace App\Support;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WarehouseAccess
{
    public static function isScoped(User $user): bool
    {
        return $user->hasRole('warehouse_manager') && ! $user->hasRole('admin');
    }

    /** @return list<int> */
    public static function warehouseIds(User $user): array
    {
        if (! self::isScoped($user)) {
            return [];
        }

        return $user->warehouses()->pluck('warehouses.id')->map(fn ($id) => (int) $id)->all();
    }

    public static function assertWarehouse(User $user, int $warehouseId): void
    {
        if (self::isScoped($user) && ! $user->warehouses()->whereKey($warehouseId)->exists()) {
            abort(403, 'هذا المخزن غير مخصص للمستخدم.');
        }
    }

    public static function assertProduct(User $user, Product $product): void
    {
        if (! self::isScoped($user)) {
            return;
        }

        $ids = self::warehouseIds($user);
        if ($ids === [] || ! $product->stockLevels()->whereIn('warehouse_id', $ids)->exists()) {
            abort(403, 'هذا الصنف غير متاح ضمن المخازن المخصصة للمستخدم.');
        }
    }

    public static function scopeWarehouseColumn(Builder $query, User $user, string $column = 'warehouse_id'): Builder
    {
        if (self::isScoped($user)) {
            $query->whereIn($column, self::warehouseIds($user));
        }

        return $query;
    }
}
