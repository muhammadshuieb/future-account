<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyExchange extends Model
{
    use HasAttachments;

    protected $fillable = [
        'exchange_number',
        'exchange_date',
        'source_cash_box_id',
        'target_cash_box_id',
        'source_currency',
        'target_currency',
        'source_amount',
        'target_amount',
        'exchange_rate',
        'source_base_amount',
        'target_base_amount',
        'status',
        'journal_entry_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'exchange_date' => 'date',
            'source_amount' => 'decimal:2',
            'target_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'source_base_amount' => 'decimal:2',
            'target_base_amount' => 'decimal:2',
        ];
    }

    public function sourceCashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'source_cash_box_id');
    }

    public function targetCashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class, 'target_cash_box_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
