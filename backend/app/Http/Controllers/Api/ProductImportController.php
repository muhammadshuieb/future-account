<?php

namespace App\Http\Controllers\Api;

use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends ApiController
{
    public function __construct(protected ProductImportService $imports) {}

    public function template(): StreamedResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->imports->downloadTemplate();
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $data['file'];
        $result = $this->imports->import($file, $request->user());

        $status = $result['imported'] > 0 ? 200 : ($result['failed'] > 0 ? 422 : 200);

        return response()->json([
            'data' => $result,
            'message' => $this->summaryMessage($result),
        ], $status);
    }

    /**
     * @param  array{imported:int,failed:int,total_rows:int,errors:list<array{row:int,message:string}>}  $result
     */
    protected function summaryMessage(array $result): string
    {
        if ($result['imported'] === 0 && $result['failed'] === 0) {
            return 'الملف لا يحتوي على صفوف بيانات للاستيراد.';
        }

        $msg = "تم استيراد {$result['imported']} صنفاً";
        if ($result['failed'] > 0) {
            $msg .= " — فشل {$result['failed']} صف";
        }

        return $msg.'.';
    }
}
