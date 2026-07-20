<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    protected $fillable = [
        'return_number', 'return_date', 'supplier_id', 'purchase_invoice_id',
        'warehouse_id', 'status', 'total', 'journal_entry_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['return_date' => 'date', 'total' => 'decimal:2'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function lines(): HasMany { return $this->hasMany(PurchaseReturnLine::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
