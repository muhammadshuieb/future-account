<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    protected function userCan(string $permission): bool
    {
        $user = auth()->user();

        return (bool) ($user && $user->can($permission));
    }

    protected function authorizePermission(string $permission): void
    {
        if (! $this->userCan($permission)) {
            abort(403, 'ليس لديك صلاحية.');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function authorizeAnyPermission(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->userCan($permission)) {
                return;
            }
        }

        abort(403, 'ليس لديك صلاحية.');
    }

    protected function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }
}
