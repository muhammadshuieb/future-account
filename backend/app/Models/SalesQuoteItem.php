<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuoteItem extends Model
{
    protected $fillable = [
        'sales_quote_id', 'product_id', 'quantity', 'unit_price', 'tax_rate', 'line_total',
        'batch_no', 'serial_no',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo { return $this->belongsTo(SalesQuote::class, 'sales_quote_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
