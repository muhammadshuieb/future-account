<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('users.manage');

        $users = User::query()->with('roles')->orderBy('name')->get()->map(fn (User $u) => $this->userPayload($u));

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
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

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

        return $this->ok($this->userPayload($user->load('roles')), 201);
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
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

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

        return $this->ok($this->userPayload($user->fresh('roles')));
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
        ];
    }
}
