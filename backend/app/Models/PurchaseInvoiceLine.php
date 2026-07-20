<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseInvoiceLine extends Model
{
    protected $fillable = [
        'purchase_invoice_id', 'product_id', 'quantity', 'unit_cost',
        'tax_rate', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
