<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    protected $fillable = [
        'request_number', 'request_date', 'required_date', 'supplier_id', 'warehouse_id', 'branch_id',
        'status', 'currency', 'exchange_rate', 'base_amount', 'subtotal', 'tax_amount', 'total',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
            'required_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function items(): HasMany { return $this->hasMany(PurchaseRequestItem::class); }
    public function orders(): HasMany { return $this->hasMany(PurchaseOrder::class); }
}
