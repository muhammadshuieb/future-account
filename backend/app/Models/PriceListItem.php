<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListItem extends Model
{
    protected $fillable = [
        'price_list_id', 'sort_order', 'category', 'row_no', 'model_no', 'mp', 'specs', 'lens',
        'product_url',
        'price_super_wholesale', 'price_retail', 'price_wholesale',
        'is_category_header', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'price_super_wholesale' => 'decimal:2',
            'price_retail' => 'decimal:2',
            'price_wholesale' => 'decimal:2',
            'is_category_header' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
