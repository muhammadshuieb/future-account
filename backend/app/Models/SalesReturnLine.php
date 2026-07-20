<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    protected $fillable = ['sales_return_id', 'product_id', 'quantity', 'unit_price', 'line_total', 'batch_no', 'serial_no'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
