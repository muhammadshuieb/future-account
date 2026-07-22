<?php

namespace App\Http\Controllers\Api;

use App\Services\WhatsAppCloudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class WhatsAppController extends ApiController
{
    public function __construct(
        protected WhatsAppCloudService $whatsapp,
    ) {}

    public function status(): JsonResponse
    {
        return $this->ok($this->whatsapp->status());
    }

    public function send(Request $request): JsonResponse
    {
        if (! $this->whatsapp->configured()) {
            return response()->json([
                'message' => 'WhatsApp Cloud API غير مربوط. استخدم فتح واتساب مع تنزيل الملف.',
                'data' => ['configured' => false],
            ], 422);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'file' => ['required', 'file', 'max:16384'],
        ]);

        try {
            $result = $this->whatsapp->sendMedia(
                $data['phone'],
                $request->file('file'),
                $data['caption'] ?? null,
            );

            return $this->ok($result);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'تعذر إرسال واتساب.'], 500);
        }
    }
}
