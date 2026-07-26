<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $query = AuditLog::query()->with('user:id,name')->latest('id');
        ListSearch::apply($query, $request, ['action', 'auditable_type', 'ip_address'], [
            'user' => ['name', 'username'],
        ]);

        return $this->ok($query->limit(200)->get());
    }
}
