<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    protected $fillable = ['purchase_return_id', 'product_id', 'quantity', 'unit_cost', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
