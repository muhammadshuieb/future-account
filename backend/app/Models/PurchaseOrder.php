<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'order_number', 'order_date', 'supplier_id', 'purchase_request_id', 'warehouse_id', 'branch_id',
        'status', 'currency', 'exchange_rate', 'base_amount', 'subtotal', 'tax_amount', 'total',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function request(): BelongsTo { return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class); }
    public function invoices(): HasMany { return $this->hasMany(PurchaseInvoice::class); }
}
