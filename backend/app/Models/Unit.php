<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    protected $fillable = ['name', 'symbol'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
