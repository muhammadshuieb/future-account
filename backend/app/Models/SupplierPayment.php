<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierPayment extends Model
{
    use HasAttachments;

    protected $fillable = [
        'payment_number', 'payment_date', 'supplier_id', 'purchase_invoice_id',
        'cash_box_id', 'bank_id', 'method', 'amount', 'applied_amount', 'currency', 'exchange_rate',
        'base_amount', 'status', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'applied_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id'); }
    public function cashBox(): BelongsTo { return $this->belongsTo(CashBox::class); }
    public function bank(): BelongsTo { return $this->belongsTo(Bank::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function allocations(): HasMany { return $this->hasMany(SupplierPaymentAllocation::class); }

    /** Portion not yet applied to purchase invoices. */
    public function unallocatedAmount(): float
    {
        return round(max(0, (float) $this->amount - (float) $this->applied_amount), 2);
    }
}
