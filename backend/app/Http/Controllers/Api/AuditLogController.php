<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('settings.manage');

        return $this->ok(AuditLog::query()->with('user:id,name')->latest('id')->limit(200)->get());
    }
}
