<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $fillable = [
        'branch_id', 'code', 'name', 'account_number', 'iban',
        'account_id', 'opening_balance', 'currency', 'is_active',
    ];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function reconciliations(): HasMany { return $this->hasMany(BankReconciliation::class); }
}
