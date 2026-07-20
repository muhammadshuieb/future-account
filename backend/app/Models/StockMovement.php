<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = [
        'movement_number', 'movement_date', 'type', 'warehouse_id', 'product_id',
        'quantity', 'unit_cost', 'batch_no', 'serial_no', 'reference_type',
        'reference_id', 'journal_entry_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
