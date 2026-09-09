<?php

namespace App\Http\Controllers\Api;

use App\Services\PurchaseInvoiceLineImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseInvoiceImportController extends ApiController
{
    public function __construct(protected PurchaseInvoiceLineImportService $imports) {}

    public function template(): StreamedResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->imports->downloadTemplate();
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['purchases.manage', 'purchases.invoices.edit']);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $result = $this->imports->preview($file);

        $status = $result['imported'] > 0 ? 200 : ($result['errors'] !== [] ? 422 : 200);

        return response()->json([
            'data' => $result,
            'message' => $this->summaryMessage($result),
        ], $status);
    }

    /**
     * @param  array{imported:int,matched:int,unmatched:int,errors:list<array{row:int,message:string}>}  $result
     */
    protected function summaryMessage(array $result): string
    {
        if ($result['imported'] === 0 && $result['errors'] === []) {
            return 'الملف لا يحتوي على صفوف بيانات للاستيراد.';
        }
        if ($result['imported'] === 0) {
            return $result['errors'][0]['message'] ?? 'تعذر قراءة بنود الملف.';
        }

        $msg = "تم تحميل {$result['imported']} بنداً إلى النموذج (لم يُحفظ بعد)";
        if ($result['unmatched'] > 0) {
            $msg .= " — {$result['unmatched']} بدون مطابقة في الدليل";
        }

        return $msg.'.';
    }
}
