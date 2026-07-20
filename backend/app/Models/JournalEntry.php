<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_number',
        'entry_date',
        'description',
        'reference',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function details(): HasMany
    {
        return $this->hasMany(JournalDetail::class)->orderBy('line_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function totalDebit(): float
    {
        return (float) $this->details()->sum('debit');
    }

    public function totalCredit(): float
    {
        return (float) $this->details()->sum('credit');
    }

    public function isBalanced(): bool
    {
        return round($this->totalDebit(), 2) === round($this->totalCredit(), 2)
            && $this->totalDebit() > 0;
    }
}
