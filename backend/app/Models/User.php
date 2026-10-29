<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouse')->withTimestamps();
    }

    public function priceLists(): BelongsToMany
    {
        return $this->belongsToMany(PriceList::class, 'user_price_list')->withTimestamps();
    }

    public function warehouseApprovalRequests(): HasMany
    {
        return $this->hasMany(WarehouseApprovalRequest::class, 'requester_id');
    }

    /**
     * Admins / managers see every list. Viewers with no assignment see all
     * (legacy default); otherwise only the assigned lists.
     */
    public function canAccessPriceList(int|PriceList $priceList): bool
    {
        if ($this->can('price_lists.manage') || $this->hasRole('admin')) {
            return true;
        }

        if (! $this->can('price_lists.view')) {
            return false;
        }

        $assignedIds = $this->relationLoaded('priceLists')
            ? $this->priceLists->pluck('id')
            : $this->priceLists()->pluck('price_lists.id');

        if ($assignedIds->isEmpty()) {
            return true;
        }

        $id = $priceList instanceof PriceList ? (int) $priceList->id : $priceList;

        return $assignedIds->contains($id);
    }

    /**
     * @return list<int>|null  null = unrestricted (see all)
     */
    public function restrictedPriceListIds(): ?array
    {
        if ($this->can('price_lists.manage') || $this->hasRole('admin')) {
            return null;
        }

        $assignedIds = $this->relationLoaded('priceLists')
            ? $this->priceLists->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : $this->priceLists()->pluck('price_lists.id')->map(fn ($id) => (int) $id)->values()->all();

        return $assignedIds === [] ? null : $assignedIds;
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
