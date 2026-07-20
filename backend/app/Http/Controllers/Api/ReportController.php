<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(protected ReportService $reports) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->trialBalance($request->query('as_of')));
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->incomeStatement($request->query('from'), $request->query('to')));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->balanceSheet($request->query('as_of')));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->cashFlow($request->query('from'), $request->query('to')));
    }

    public function sales(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->salesReport(
            $request->query('from'),
            $request->query('to'),
            $request->filled('branch_id') ? (int) $request->query('branch_id') : null,
        ));
    }

    public function purchases(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->purchaseReport(
            $request->query('from'),
            $request->query('to'),
            $request->filled('branch_id') ? (int) $request->query('branch_id') : null,
        ));
    }

    public function inventory(): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->inventoryReport());
    }

    public function profit(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->profitReport($request->query('from'), $request->query('to')));
    }

    public function tax(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->taxReport($request->query('from'), $request->query('to')));
    }

    public function productMovement(Request $request, int $product): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->productMovement($product, $request->query('from'), $request->query('to')));
    }
}
