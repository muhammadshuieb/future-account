<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLogger
{
    protected static bool $enabled = true;

    public static function withoutAuditing(callable $callback): mixed
    {
        $previous = static::$enabled;
        static::$enabled = false;

        try {
            return $callback();
        } finally {
            static::$enabled = $previous;
        }
    }

    public function log(
        ?User $user,
        string $action,
        ?Model $model = null,
        ?array $old = null,
        ?array $new = null
    ): ?AuditLog {
        if (! static::$enabled) {
            return null;
        }

        return static::withoutAuditing(fn () => AuditLog::query()->create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old ? $this->sanitize($old) : null,
            'new_values' => $new ? $this->sanitize($new) : null,
            'ip_address' => request()?->ip(),
        ]));
    }

    /**
     * Record a generic create/update/delete for an auditable model.
     */
    public function record(Model $model, string $event): ?AuditLog
    {
        if (! static::$enabled) {
            return null;
        }

        // Seeders / console without a user should not flood the log.
        if (! auth()->check()) {
            return null;
        }

        $old = null;
        $new = null;

        if ($event === 'created') {
            $new = $model->getAttributes();
        } elseif ($event === 'updated') {
            $changes = $model->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return null;
            }
            $old = array_intersect_key($model->getOriginal(), $changes);
            $new = $changes;
        } elseif ($event === 'deleted') {
            $old = $model->getAttributes();
        }

        $basename = Str::snake(class_basename($model));

        return $this->log(auth()->user(), "{$basename}.{$event}", $model, $old, $new);
    }

    protected function sanitize(array $values): array
    {
        $hidden = config('audit.hidden_attributes', ['password', 'remember_token']);

        foreach ($hidden as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
