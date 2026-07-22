<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudService
{
    public function configured(): bool
    {
        return filled(env('WHATSAPP_TOKEN')) && filled(env('WHATSAPP_PHONE_NUMBER_ID'));
    }

    /**
     * @return array{configured: bool, phone_number_id: string|null}
     */
    public function status(): array
    {
        return [
            'configured' => $this->configured(),
            'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID') ? '***' : null,
        ];
    }

    /**
     * Normalize Syrian / local numbers to digits-only international (no +).
     */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '963') && strlen($digits) >= 12) {
            return $digits;
        }
        if (str_starts_with($digits, '09') && strlen($digits) === 10) {
            return '963'.substr($digits, 1);
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 9) {
            return '963'.$digits;
        }
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return $digits;
        }

        throw new RuntimeException('رقم الجوال غير صالح.');
    }

    /**
     * Upload media then send as document or image via Cloud API.
     *
     * @return array{ok: bool, message_id?: string, media_id?: string}
     */
    public function sendMedia(string $phone, UploadedFile $file, ?string $caption = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WhatsApp Cloud API غير مربوط.');
        }

        $to = $this->normalizePhone($phone);
        $token = (string) env('WHATSAPP_TOKEN');
        $phoneNumberId = (string) env('WHATSAPP_PHONE_NUMBER_ID');
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $isImage = str_starts_with($mime, 'image/');

        $mediaId = $this->uploadMedia($token, $phoneNumberId, $file, $mime);

        $type = $isImage ? 'image' : 'document';
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => $type,
            $type => array_filter([
                'id' => $mediaId,
                'caption' => $caption,
                'filename' => $isImage ? null : $file->getClientOriginalName(),
            ]),
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('فشل إرسال واتساب: '.$response->body());
        }

        $messageId = data_get($response->json(), 'messages.0.id');

        return [
            'ok' => true,
            'message_id' => is_string($messageId) ? $messageId : null,
            'media_id' => $mediaId,
        ];
    }

    protected function uploadMedia(string $token, string $phoneNumberId, UploadedFile $file, string $mime): string
    {
        try {
            $response = Http::withToken($token)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()) ?: '',
                    $file->getClientOriginalName(),
                    ['Content-Type' => $mime],
                )
                ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/media", [
                    'messaging_product' => 'whatsapp',
                    'type' => $mime,
                ]);
        } catch (RequestException $e) {
            throw new RuntimeException('فشل رفع ملف واتساب: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException('فشل رفع ملف واتساب: '.$response->body());
        }

        $id = data_get($response->json(), 'id');
        if (! is_string($id) || $id === '') {
            throw new RuntimeException('استجابة رفع واتساب بدون media id.');
        }

        return $id;
    }
}
