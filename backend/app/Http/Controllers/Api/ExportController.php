<?php

namespace App\Http\Controllers\Api;

use App\Services\ExcelExportService;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends ApiController
{
    public function __construct(protected ExcelExportService $exports) {}

    public function module(Request $request, string $module): StreamedResponse
    {
        $permissions = ExcelExportService::modulePermissions();
        if (! isset($permissions[$module])) {
            abort(404, 'وحدة التصدير غير موجودة.');
        }
        $this->authorizePermission($permissions[$module]);

        try {
            return $this->exports->downloadModule($module, $request);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function report(Request $request, string $type): StreamedResponse
    {
        $this->authorizePermission('reports.view');

        try {
            return $this->exports->downloadReport($type, $request);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function full(): StreamedResponse
    {
        $user = auth()->user();
        if (! $user || ! $user->hasRole('admin')) {
            abort(403, 'التصدير الشامل متاح لمديري النظام فقط.');
        }

        return $this->exports->downloadFullArchive();
    }
}
