<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends ApiController
{
    /** @var array<string, string> */
    private const PRICE_COLUMN_PERMS = [
        'retail' => 'price_lists.prices.retail',
        'wholesale' => 'price_lists.prices.wholesale',
        'super_wholesale' => 'price_lists.prices.super_wholesale',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('users.manage');

        $query = User::query()->with(['roles', 'permissions', 'warehouses:id,name,code'])->orderBy('name');
        ListSearch::apply($query, $request, ['name', 'username', 'email', 'first_name', 'last_name']);
        $users = $query->get()->map(fn (User $u) => $this->userPayload($u));

        return $this->ok($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'mobile' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'distinct', 'exists:warehouses,id'],
            'price_list_columns' => ['nullable', 'array'],
            'price_list_columns.*' => ['string', Rule::in(array_keys(self::PRICE_COLUMN_PERMS))],
        ]);
        if (in_array('warehouse_manager', $data['roles'] ?? [], true) && empty($data['warehouse_ids'])) {
            throw ValidationException::withMessages(['warehouse_ids' => ['يجب تخصيص مخزن واحد على الأقل لمسؤول المخزن.']]);
        }

        $name = User::composeDisplayName($data['first_name'], $data['last_name']);
        $email = $data['email'] ?? ($data['username'].'@users.local');

        $user = User::query()->create([
            'name' => $name,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'username' => $data['username'],
            'mobile' => $data['mobile'],
            'email' => $email,
            'password' => $data['password'],
            'is_active' => $data['is_active'] ?? true,
        ]);
        if (! empty($data['roles'])) {
            $user->syncRoles($data['roles']);
        }
        $user->warehouses()->sync($data['warehouse_ids'] ?? []);
        if (array_key_exists('price_list_columns', $data)) {
            $this->syncPriceColumnPermissions($user, $data['price_list_columns'] ?? []);
        }

        return $this->ok($this->userPayload($user->load(['roles', 'permissions', 'warehouses:id,name,code'])), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'mobile' => ['sometimes', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'is_active' => ['boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', 'exists:roles,name'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'distinct', 'exists:warehouses,id'],
            'price_list_columns' => ['nullable', 'array'],
            'price_list_columns.*' => ['string', Rule::in(array_keys(self::PRICE_COLUMN_PERMS))],
        ]);
        $nextRoles = $data['roles'] ?? $user->getRoleNames()->all();
        $nextWarehouses = $data['warehouse_ids'] ?? $user->warehouses()->pluck('warehouses.id')->all();
        if (array_key_exists('roles', $data) && empty($data['roles'])) {
            throw ValidationException::withMessages(['roles' => ['يجب اختيار دور واحد على الأقل.']]);
        }
        if (in_array('warehouse_manager', $nextRoles, true) && empty($nextWarehouses)) {
            throw ValidationException::withMessages(['warehouse_ids' => ['يجب تخصيص مخزن واحد على الأقل لمسؤول المخزن.']]);
        }

        if (isset($data['first_name'])) {
            $user->first_name = $data['first_name'];
        }
        if (isset($data['last_name'])) {
            $user->last_name = $data['last_name'];
        }
        if (isset($data['username'])) {
            $user->username = $data['username'];
        }
        if (array_key_exists('mobile', $data)) {
            $user->mobile = $data['mobile'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
        }

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $user->name = User::composeDisplayName($user->first_name, $user->last_name, $user->name);
        }

        $user->save();

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }
        if (array_key_exists('warehouse_ids', $data)) {
            $user->warehouses()->sync($data['warehouse_ids'] ?? []);
        }
        if (array_key_exists('price_list_columns', $data)) {
            $this->syncPriceColumnPermissions($user, $data['price_list_columns'] ?? []);
        }

        return $this->ok($this->userPayload($user->fresh(['roles', 'permissions', 'warehouses:id,name,code'])));
    }

    public function destroy(User $user): JsonResponse
    {
        $this->authorizePermission('users.manage');
        if ($user->id === auth()->id()) {
            abort(422, 'لا يمكن حذف المستخدم الحالي.');
        }
        $user->delete();

        return $this->ok(['deleted' => true]);
    }

    protected function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'price_list_columns' => $this->priceListColumnsFor($user),
            'warehouse_ids' => $user->warehouses->pluck('id'),
            'warehouses' => $user->warehouses->map->only(['id', 'name', 'code'])->values(),
        ];
    }

    /**
     * @return list<string>
     */
    private function priceListColumnsFor(User $user): array
    {
        if ($user->can('price_lists.manage') || $user->hasRole('admin')) {
            return array_keys(self::PRICE_COLUMN_PERMS);
        }

        $cols = [];
        foreach (self::PRICE_COLUMN_PERMS as $col => $perm) {
            if ($user->can($perm)) {
                $cols[] = $col;
            }
        }

        return $cols;
    }

    /**
     * Sync only the three price-column permissions as direct user permissions
     * (keeps any other direct permissions intact).
     *
     * @param  list<string>  $columns
     */
    private function syncPriceColumnPermissions(User $user, array $columns): void
    {
        $wanted = [];
        foreach ($columns as $col) {
            if (isset(self::PRICE_COLUMN_PERMS[$col])) {
                $wanted[] = self::PRICE_COLUMN_PERMS[$col];
            }
        }

        $pricePerms = array_values(self::PRICE_COLUMN_PERMS);
        $otherDirect = $user->permissions
            ->pluck('name')
            ->reject(fn ($name) => in_array($name, $pricePerms, true))
            ->values()
            ->all();

        $user->syncPermissions(array_values(array_unique([...$otherDirect, ...$wanted])));
    }
}
