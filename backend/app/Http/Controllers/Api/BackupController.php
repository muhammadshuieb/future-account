<?php

namespace App\Http\Controllers\Api;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends ApiController
{
    public function __construct(protected BackupService $backups) {}

    protected function authorizeAdmin(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRole('admin')) {
            abort(403, 'النسخ الاحتياطي متاح لمديري النظام فقط.');
        }
    }

    public function index(): JsonResponse
    {
        $this->authorizeAdmin();

        return $this->ok($this->backups->list());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->ok($this->backups->create($data['label'] ?? null), 201);
    }

    public function download(string $filename): BinaryFileResponse
    {
        $this->authorizeAdmin();

        $path = $this->backups->pathFor($filename);

        return response()->download($path, basename($path));
    }

    public function restore(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'filename' => ['required', 'string'],
            'confirm' => ['required', 'accepted'],
        ]);

        $this->backups->restore($data['filename']);

        return $this->ok(['message' => 'تمت استعادة النسخة الاحتياطية. قد تحتاج لإعادة تسجيل الدخول.']);
    }

    public function destroy(string $filename): JsonResponse
    {
        $this->authorizeAdmin();
        $this->backups->delete($filename);

        return $this->ok(['message' => 'تم حذف الملف.']);
    }
}
