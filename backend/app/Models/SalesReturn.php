<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    protected $fillable = [
        'return_number', 'return_date', 'customer_id', 'sales_invoice_id',
        'warehouse_id', 'status', 'total', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['return_date' => 'date', 'total' => 'decimal:2'];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function lines(): HasMany { return $this->hasMany(SalesReturnLine::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
