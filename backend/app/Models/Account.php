<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'code',
        'name',
        'name_en',
        'parent_id',
        'type',
        'nature',
        'level',
        'is_group',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'is_active' => 'boolean',
            'level' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id')->orderBy('code');
    }

    public function journalDetails(): HasMany
    {
        return $this->hasMany(JournalDetail::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
