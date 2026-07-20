<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(
        ?User $user,
        string $action,
        ?Model $model = null,
        ?array $old = null,
        ?array $new = null
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}
