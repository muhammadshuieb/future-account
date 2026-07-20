<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'branch_id', 'code', 'name', 'phone', 'email', 'address',
        'tax_number', 'credit_limit', 'account_id', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
