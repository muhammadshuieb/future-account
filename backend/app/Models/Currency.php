<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $fillable = [
        'code', 'name', 'name_en', 'symbol', 'decimal_places', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'decimal_places' => 'integer',
        ];
    }

    public function exchangeRatesFrom(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'from_currency', 'code');
    }
}
