<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    protected $fillable = [
        'purchase_request_id', 'product_id', 'quantity', 'unit_cost', 'tax_rate', 'line_total',
        'batch_no', 'serial_no',
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

    public function request(): BelongsTo { return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
