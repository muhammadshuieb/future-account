<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseInvoice extends Model
{
    use HasAttachments;

    protected $fillable = [
        'invoice_number', 'invoice_date', 'supplier_id', 'warehouse_id', 'cash_box_id', 'branch_id',
        'purchase_order_id', 'status', 'payment_type', 'currency', 'exchange_rate', 'base_amount',
        'subtotal', 'tax_amount', 'customs_amount', 'transport_fees', 'fines_amount', 'other_fees',
        'total', 'paid_amount', 'notes',
        'journal_entry_id', 'created_by', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'posted_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'customs_amount' => 'decimal:2',
            'transport_fees' => 'decimal:2',
            'fines_amount' => 'decimal:2',
            'other_fees' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    /** Optional landed-cost extras (customs, transport, fines, other) in document currency. */
    public function extrasTotal(): float
    {
        return round(
            (float) $this->customs_amount
            + (float) $this->transport_fees
            + (float) $this->fines_amount
            + (float) $this->other_fees,
            2
        );
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function purchaseOrder(): BelongsTo { return $this->belongsTo(PurchaseOrder::class); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function cashBox(): BelongsTo { return $this->belongsTo(CashBox::class); }
    public function lines(): HasMany { return $this->hasMany(PurchaseInvoiceLine::class); }
    public function payments(): HasMany { return $this->hasMany(SupplierPayment::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
