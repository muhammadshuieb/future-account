<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Setting::query()->orderBy('group')->orderBy('key')->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable'],
        ]);

        foreach ($data['settings'] as $item) {
            Setting::setValue($item['key'], $item['value'] ?? '');
        }

        return response()->json([
            'data' => Setting::query()->orderBy('group')->orderBy('key')->get(),
            'message' => 'تم حفظ الإعدادات.',
        ]);
    }
}
