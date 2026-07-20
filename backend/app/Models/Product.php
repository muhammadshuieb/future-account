<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku', 'barcode', 'name', 'description', 'category_id', 'unit_id',
        'inventory_account_id', 'cogs_account_id', 'sales_account_id',
        'cost_price', 'sale_price', 'reorder_level',
        'track_batch', 'track_serial', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'track_batch' => 'boolean',
            'track_serial' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function totalStock(): float
    {
        return (float) $this->stockLevels()->sum('quantity');
    }
}
