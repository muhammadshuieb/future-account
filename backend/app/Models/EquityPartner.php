<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquityPartner extends Model
{
    protected $fillable = [
        'code', 'name', 'share_percent', 'account_id', 'phone', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function distributionLines(): HasMany
    {
        return $this->hasMany(ProfitDistributionLine::class);
    }
}
