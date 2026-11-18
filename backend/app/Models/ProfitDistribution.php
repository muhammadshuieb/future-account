<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfitDistribution extends Model
{
    protected $fillable = [
        'distribution_number', 'distribution_date', 'cash_box_id', 'total_amount',
        'currency', 'exchange_rate', 'base_amount', 'status', 'journal_entry_id',
        'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'distribution_date' => 'date',
            'total_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:8',
            'base_amount' => 'decimal:2',
        ];
    }

    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProfitDistributionLine::class);
    }
}
