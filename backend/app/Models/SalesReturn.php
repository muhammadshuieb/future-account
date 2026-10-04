<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    protected $fillable = [
        'return_number', 'return_date', 'customer_id', 'sales_invoice_id',
        'warehouse_id', 'status', 'currency', 'exchange_rate', 'base_amount',
        'total', 'applied_amount', 'refund_amount', 'cash_box_id',
        'journal_entry_id', 'refund_journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'total' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function cashBox(): BelongsTo { return $this->belongsTo(CashBox::class); }
    public function lines(): HasMany { return $this->hasMany(SalesReturnLine::class); }
    public function allocations(): HasMany { return $this->hasMany(SalesReturnAllocation::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function refundJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'refund_journal_entry_id'); }

    /** Portion of return credit not yet applied to invoices or refunded in cash. */
    public function unallocatedAmount(): float
    {
        return round(max(0, (float) $this->total - (float) $this->applied_amount - (float) $this->refund_amount), 2);
    }
}
