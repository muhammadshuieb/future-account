<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FtthSector extends Model
{
    protected $fillable = [
        'sort_order',
        'name',
        'from_point',
        'to_point',
        'planned_length_m',
        'excavation_done_m',
        'duct_done_m',
        'manholes_done',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'planned_length_m' => 'decimal:3',
            'excavation_done_m' => 'decimal:3',
            'duct_done_m' => 'decimal:3',
            'manholes_done' => 'integer',
        ];
    }
}
