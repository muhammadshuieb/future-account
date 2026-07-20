<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalDetail extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'memo',
        'line_order',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'line_order' => 'integer',
        ];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
