<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    protected $fillable = [
        'invoice_number', 'invoice_date', 'customer_id', 'warehouse_id', 'branch_id',
        'status', 'currency', 'exchange_rate', 'base_amount',
        'subtotal', 'tax_amount', 'total', 'paid_amount', 'notes',
        'journal_entry_id', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'posted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function lines(): HasMany { return $this->hasMany(SalesInvoiceLine::class); }
    public function receipts(): HasMany { return $this->hasMany(Receipt::class); }
}
