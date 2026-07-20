<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'type',
        'label',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $value = Cache::remember("setting.{$key}", 3600, function () use ($key) {
            return static::query()->where('key', $key)->value('value');
        });

        return $value ?? $default;
    }

    public static function setValue(string $key, mixed $value, string $group = 'general', string $type = 'string', ?string $label = null): self
    {
        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => is_array($value) || is_object($value) ? json_encode($value) : (string) $value,
                'group' => $group,
                'type' => $type,
                'label' => $label,
            ]
        );

        Cache::forget("setting.{$key}");

        return $setting;
    }
}
