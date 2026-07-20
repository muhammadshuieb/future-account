<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('users.manage');

        $roles = Role::query()->with('permissions')->orderBy('name')->get()->map(fn (Role $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'permissions' => $r->permissions->pluck('name'),
        ]);

        $permissions = Permission::query()->orderBy('name')->pluck('name');

        return $this->ok(['roles' => $roles, 'permissions' => $permissions]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $this->authorizePermission('users.manage');
        if ($role->name === 'admin') {
            abort(422, 'لا يمكن تعديل صلاحيات دور المدير.');
        }

        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($data['permissions']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $this->ok([
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->fresh('permissions')->permissions->pluck('name'),
        ]);
    }
}
