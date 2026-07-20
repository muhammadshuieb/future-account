<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    protected $fillable = [
        'sales_invoice_id', 'product_id', 'quantity', 'unit_price',
        'tax_rate', 'line_total', 'cost_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
}
