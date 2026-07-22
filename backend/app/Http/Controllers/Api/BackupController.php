<?php

namespace App\Http\Controllers\Api;

use App\Services\BackupDistributionService;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends ApiController
{
    public function __construct(
        protected BackupService $backups,
        protected BackupDistributionService $distribution,
    ) {}

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

    public function status(): JsonResponse
    {
        $this->authorizeAdmin();

        return $this->ok($this->distribution->status());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:64'],
        ]);

        $meta = $this->backups->create($data['label'] ?? null);
        $meta['distribution'] = $this->distribution->distribute($meta['path'], $meta['filename']);

        if (! $this->distribution->googleDriveConfigured()) {
            app(\App\Services\AppNotificationService::class)->notifyAdminsOnceDaily(
                'backup_drive_missing',
                'Google Drive غير مربوط',
                'النسخ الاحتياطي يعمل محلياً فقط. اربط Google Drive من إعدادات الخادم لتلافي فقدان النسخ.',
                ['google_drive' => false],
            );
        }

        $failed = collect($meta['distribution'])
            ->filter(fn ($r) => ! ($r['skipped'] ?? false) && ! ($r['ok'] ?? false));

        if ($failed->isNotEmpty()) {
            $errors = $failed->map(fn ($r, $dest) => $dest.': '.($r['error'] ?? 'unknown'))->implode(' | ');
            app(\App\Services\AppNotificationService::class)->notifyAdmins(
                'backup_failed',
                'فشل رفع النسخة الاحتياطية',
                'تم إنشاء النسخة محلياً لكن فشل الرفع: '.$errors,
                ['filename' => $meta['filename'], 'distribution' => $meta['distribution']],
            );
        }

        return $this->ok($meta, 201);
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

        try {
            $this->backups->restore($data['filename']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->ok(['message' => 'تمت استعادة النسخة الاحتياطية. قد تحتاج لإعادة تسجيل الدخول.']);
    }

    public function restoreUpload(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $request->validate([
            'file' => ['required', 'file', 'max:'.BackupService::MAX_UPLOAD_KB],
            'confirm' => ['required', 'accepted'],
        ], [
            'file.required' => 'يرجى اختيار ملف النسخة الاحتياطية.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.max' => 'حجم الملف أكبر من الحد المسموح (512 ميجابايت).',
            'confirm.accepted' => 'يجب تأكيد عملية الاستعادة.',
        ]);

        $file = $request->file('file');
        $original = (string) $file->getClientOriginalName();

        if (! $this->backups->isAllowedUploadName($original)) {
            return response()->json([
                'message' => 'امتداد الملف غير مدعوم. المسموح: .sql و .dump و .backup و .gz',
            ], 422);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension()) ?: 'dump';
        if (! in_array($ext, BackupService::ALLOWED_EXTENSIONS, true)) {
            return response()->json([
                'message' => 'امتداد الملف غير مدعوم. المسموح: .sql و .dump و .backup و .gz',
            ], 422);
        }

        $storedName = 'upload_'.uniqid('', true).'.'.$ext;
        $storedPath = $this->backups->uploadDirectory().DIRECTORY_SEPARATOR.$storedName;

        try {
            $file->move($this->backups->uploadDirectory(), $storedName);
            $this->backups->restoreFromPath($storedPath);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } finally {
            if (File::exists($storedPath)) {
                File::delete($storedPath);
            }
        }

        return $this->ok([
            'message' => 'تمت استعادة النسخة الاحتياطية من الملف المرفوع. قد تحتاج لإعادة تسجيل الدخول.',
        ]);
    }

    public function destroy(string $filename): JsonResponse
    {
        $this->authorizeAdmin();
        $this->backups->delete($filename);

        return $this->ok(['message' => 'تم حذف الملف.']);
    }
}
