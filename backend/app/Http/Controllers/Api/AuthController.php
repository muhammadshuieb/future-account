<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:64'],
            'client' => ['nullable', 'string', 'in:web,android,windows,ios'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        if (isset($user->is_active) && ! $user->is_active) {
            throw ValidationException::withMessages([
                'username' => ['هذا الحساب معطل.'],
            ]);
        }

        $client = $credentials['client'] ?? 'web';
        $deviceName = trim((string) ($credentials['device_name'] ?? '')) ?: $client;
        $expiresAt = in_array($client, ['android', 'windows', 'ios'], true)
            ? now()->addDays(90)
            : null;
        $token = $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
            'landing_path' => $this->landingPath($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    protected function userPayload(User $user): array
    {
        $user->loadMissing('roles', 'permissions', 'warehouses:id,name,code');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'mobile' => $user->mobile,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'warehouse_ids' => $user->warehouses->pluck('id'),
            'warehouses' => $user->warehouses->map->only(['id', 'name', 'code'])->values(),
        ];
    }

    protected function landingPath(User $user): string
    {
        return $user->hasRole('warehouse_manager') && ! $user->hasRole('admin')
            ? '/warehouse-dashboard'
            : '/';
    }
}
