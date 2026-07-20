<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency', 'to_currency', 'rate', 'rate_date', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'rate_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
