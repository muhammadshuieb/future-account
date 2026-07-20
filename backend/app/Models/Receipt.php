<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    protected $fillable = [
        'receipt_number', 'receipt_date', 'customer_id', 'sales_invoice_id',
        'cash_box_id', 'bank_id', 'method', 'amount', 'status',
        'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function cashBox(): BelongsTo { return $this->belongsTo(CashBox::class); }
    public function bank(): BelongsTo { return $this->belongsTo(Bank::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
