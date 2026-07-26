<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
