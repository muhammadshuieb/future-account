<?php

namespace App\Http\Controllers\Api;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $items = AppNotification::query()
            ->where(function ($q) use ($user) {
                $q->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $this->ok([
            'items' => $items,
            'unread_count' => $items->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        if ($notification->user_id && $notification->user_id !== $request->user()->id) {
            abort(403);
        }

        $notification->update(['read_at' => now()]);

        return $this->ok($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::query()
            ->where(function ($q) use ($request) {
                $q->whereNull('user_id')->orWhere('user_id', $request->user()->id);
            })
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->ok(['message' => 'تم تعليم الكل كمقروء.']);
    }
}
