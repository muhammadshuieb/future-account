<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends ApiController
{
    public function __construct(protected AttachmentService $attachments) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'string'],
            'attachable_id' => ['required', 'integer'],
        ]);

        $type = $this->attachments->resolveType($data['attachable_type']);
        $this->authorizePermission($this->attachments->viewPermission($type));
        $model = $this->attachments->resolveModel($type, (int) $data['attachable_id']);

        $rows = Attachment::query()
            ->where('attachable_type', $model::class)
            ->where('attachable_id', $model->getKey())
            ->latest('id')
            ->get();

        return $this->ok($rows);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', 'string'],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $type = $this->attachments->resolveType($data['attachable_type']);
        $this->authorizePermission($this->attachments->managePermission($type));
        $model = $this->attachments->resolveModel($type, (int) $data['attachable_id']);

        $attachment = $this->attachments->store($model, $request->file('file'), $request->user());

        return $this->ok($attachment, 201);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $type = $this->typeKeyFor($attachment);
        $this->authorizePermission($this->attachments->viewPermission($type));

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->path)) {
            abort(404, 'الملف غير موجود.');
        }

        return $disk->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
        ]);
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $type = $this->typeKeyFor($attachment);
        $this->authorizePermission($this->attachments->managePermission($type));
        $this->attachments->delete($attachment);

        return response()->json(['message' => 'تم حذف المرفق.']);
    }

    protected function typeKeyFor(Attachment $attachment): string
    {
        foreach (AttachmentService::TYPE_MAP as $key => $class) {
            if ($attachment->attachable_type === $class) {
                return $key;
            }
        }

        abort(422, 'نوع المرفق غير مدعوم.');
    }
}
