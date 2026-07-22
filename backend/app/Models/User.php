<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'username', 'first_name', 'last_name', 'mobile', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public static function composeDisplayName(?string $firstName, ?string $lastName, ?string $fallback = null): string
    {
        $composed = trim(implode(' ', array_filter([
            $firstName !== null ? trim($firstName) : null,
            $lastName !== null ? trim($lastName) : null,
        ], fn ($v) => $v !== null && $v !== '')));

        if ($composed !== '') {
            return $composed;
        }

        return $fallback !== null && trim($fallback) !== '' ? trim($fallback) : 'مستخدم';
    }
}
