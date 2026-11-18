<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitDistributionLine extends Model
{
    protected $fillable = [
        'profit_distribution_id', 'equity_partner_id', 'share_percent', 'amount', 'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'share_percent' => 'decimal:4',
            'amount' => 'decimal:2',
            'base_amount' => 'decimal:2',
        ];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ProfitDistribution::class, 'profit_distribution_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(EquityPartner::class, 'equity_partner_id');
    }
}
