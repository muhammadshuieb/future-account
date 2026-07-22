<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Spatie\Permission\Models\Role;

class AppNotificationService
{
    /** @return list<int|null> */
    protected function adminUserIds(): array
    {
        try {
            if (! Role::query()->where('name', 'admin')->exists()) {
                return [null];
            }

            $ids = User::role('admin')->where('is_active', true)->pluck('id')->all();

            return $ids === [] ? [null] : $ids;
        } catch (\Throwable) {
            return [null];
        }
    }

    public function notifyAdmins(string $type, string $title, string $body, array $data = []): void
    {
        foreach ($this->adminUserIds() as $userId) {
            AppNotification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        }
    }

    /**
     * Create at most one unread notification of this type per admin per calendar day.
     */
    public function notifyAdminsOnceDaily(string $type, string $title, string $body, array $data = []): void
    {
        foreach ($this->adminUserIds() as $userId) {
            $exists = AppNotification::query()
                ->where('type', $type)
                ->whereDate('created_at', today())
                ->when(
                    $userId === null,
                    fn ($q) => $q->whereNull('user_id'),
                    fn ($q) => $q->where('user_id', $userId),
                )
                ->exists();

            if ($exists) {
                continue;
            }

            AppNotification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        }
    }
}
