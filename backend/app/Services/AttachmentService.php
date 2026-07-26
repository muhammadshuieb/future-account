<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\CurrencyExchange;
use App\Models\PurchaseInvoice;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachmentService
{
    /** @var array<string, class-string<Model>> */
    public const TYPE_MAP = [
        'sales_invoice' => SalesInvoice::class,
        'purchase_invoice' => PurchaseInvoice::class,
        'currency_exchange' => CurrencyExchange::class,
        'receipt' => Receipt::class,
        'supplier_payment' => SupplierPayment::class,
    ];

    /** @var array<string, string> */
    public const PERMISSION_MAP = [
        'sales_invoice' => 'sales.manage',
        'purchase_invoice' => 'purchases.manage',
        'currency_exchange' => 'cash.manage',
        'receipt' => 'sales.manage',
        'supplier_payment' => 'purchases.manage',
    ];

    /** @var array<string, string> */
    public const VIEW_PERMISSION_MAP = [
        'sales_invoice' => 'sales.view',
        'purchase_invoice' => 'purchases.view',
        'currency_exchange' => 'cash.view',
        'receipt' => 'sales.view',
        'supplier_payment' => 'purchases.view',
    ];

    public function resolveType(string $type): string
    {
        $key = strtolower(trim($type));
        if (! isset(self::TYPE_MAP[$key])) {
            throw ValidationException::withMessages([
                'attachable_type' => ['نوع المرفق غير مدعوم.'],
            ]);
        }

        return $key;
    }

    public function resolveModel(string $type, int $id): Model
    {
        $key = $this->resolveType($type);
        $class = self::TYPE_MAP[$key];

        return $class::query()->findOrFail($id);
    }

    public function managePermission(string $type): string
    {
        return self::PERMISSION_MAP[$this->resolveType($type)];
    }

    public function viewPermission(string $type): string
    {
        return self::VIEW_PERMISSION_MAP[$this->resolveType($type)];
    }

    public function store(Model $attachable, UploadedFile $file, User $user): Attachment
    {
        $this->assertValidFile($file);

        $disk = 'attachments';
        $dir = class_basename($attachable).'/'.$attachable->getKey();
        $path = $file->store($dir, $disk);

        if (! $path) {
            throw ValidationException::withMessages(['file' => ['تعذر حفظ الملف.']]);
        }

        return Attachment::query()->create([
            'attachable_type' => $attachable::class,
            'attachable_id' => $attachable->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $user->id,
        ]);
    }

    public function delete(Attachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);
        $attachment->delete();
    }

    protected function assertValidFile(UploadedFile $file): void
    {
        $maxBytes = 5 * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => ['حجم الملف يجب ألا يتجاوز 5 ميغابايت.'],
            ]);
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $ext = strtolower($file->getClientOriginalExtension());
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

        if ((! $mime || ! in_array($mime, $allowed, true)) && ! in_array($ext, $allowedExt, true)) {
            throw ValidationException::withMessages([
                'file' => ['يُسمح بصور JPG/PNG/WebP أو ملفات PDF فقط.'],
            ]);
        }
    }
}
