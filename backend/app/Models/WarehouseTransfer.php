<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseTransfer extends Model
{
    protected $fillable = [
        'transfer_number', 'transfer_date', 'from_warehouse_id', 'to_warehouse_id',
        'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['transfer_date' => 'date'];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseTransferLine::class);
    }
}
