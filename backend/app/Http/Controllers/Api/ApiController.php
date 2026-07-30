<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class ApiController extends Controller
{
    protected function authorizePermission(string $permission): void
    {
        $user = auth()->user();

        if (! $user || (! $user->can($permission) && ! $user->hasRole('admin'))) {
            abort(403, 'ليس لديك صلاحية.');
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function authorizeAnyPermission(array $permissions): void
    {
        $user = auth()->user();

        if (! $user || $user->hasRole('admin')) {
            if (! $user) {
                abort(403, 'ليس لديك صلاحية.');
            }

            return;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
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
