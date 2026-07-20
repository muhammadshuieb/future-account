<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    protected $fillable = ['count_number', 'warehouse_id', 'count_date', 'status', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['count_date' => 'date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class);
    }
}
