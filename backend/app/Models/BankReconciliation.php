<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    protected $fillable = [
        'bank_id', 'statement_date', 'statement_balance', 'book_balance',
        'difference', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'decimal:2',
            'book_balance' => 'decimal:2',
            'difference' => 'decimal:2',
        ];
    }

    public function bank(): BelongsTo { return $this->belongsTo(Bank::class); }
}
