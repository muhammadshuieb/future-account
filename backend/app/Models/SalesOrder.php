<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    protected $fillable = [
        'order_number', 'order_date', 'customer_id', 'sales_quote_id', 'warehouse_id', 'branch_id',
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

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function quote(): BelongsTo { return $this->belongsTo(SalesQuote::class, 'sales_quote_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function items(): HasMany { return $this->hasMany(SalesOrderItem::class); }
    public function invoices(): HasMany { return $this->hasMany(SalesInvoice::class); }
}
