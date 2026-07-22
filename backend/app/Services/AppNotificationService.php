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
        $data = $this->withHref($type, $data);

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
        $data = $this->withHref($type, $data);

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

    /** @param  array<string, mixed>  $data */
    protected function withHref(string $type, array $data): array
    {
        if (isset($data['href']) && is_string($data['href']) && $data['href'] !== '') {
            return $data;
        }

        $map = [
            'backup_drive_missing' => '/settings?tab=backup',
            'backup_failed' => '/settings?tab=backup',
            'low_stock' => '/warehouse?tab=alerts',
            'receivables' => '/partners?tab=customers',
            'payables' => '/partners?tab=suppliers',
            'draft_journals' => '/journal-entries',
        ];

        if (isset($map[$type])) {
            $data['href'] = $map[$type];
        }

        return $data;
    }
}
