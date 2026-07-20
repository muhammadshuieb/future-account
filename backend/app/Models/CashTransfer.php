<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransfer extends Model
{
    protected $fillable = [
        'transfer_number', 'transfer_date', 'from_type', 'from_id',
        'to_type', 'to_id', 'amount', 'status', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['transfer_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
