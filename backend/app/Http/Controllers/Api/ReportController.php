<?php

namespace App\Http\Controllers\Api;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(protected ReportService $reports) {}

    protected function branchId(Request $request): ?int
    {
        return $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->trialBalance($request->query('as_of'), $this->branchId($request)));
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->incomeStatement($request->query('from'), $request->query('to'), $this->branchId($request)));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->balanceSheet($request->query('as_of'), $this->branchId($request)));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->cashFlow($request->query('from'), $request->query('to'), $this->branchId($request)));
    }

    public function sales(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->salesReport(
            $request->query('from'),
            $request->query('to'),
            $this->branchId($request),
        ));
    }

    public function purchases(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->purchaseReport(
            $request->query('from'),
            $request->query('to'),
            $this->branchId($request),
        ));
    }

    public function inventory(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->inventoryReport($this->branchId($request)));
    }

    public function profit(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->profitReport($request->query('from'), $request->query('to'), $this->branchId($request)));
    }

    public function tax(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->taxReport($request->query('from'), $request->query('to'), $this->branchId($request)));
    }

    public function productMovement(Request $request, int $product): JsonResponse
    {
        $this->authorizePermission('reports.view');

        return $this->ok($this->reports->productMovement(
            $product,
            $request->query('from'),
            $request->query('to'),
            $this->branchId($request),
        ));
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $request->validate(['account_id' => ['required', 'integer', 'exists:accounts,id']]);

        return $this->ok($this->reports->generalLedger(
            (int) $request->query('account_id'),
            $request->query('from'),
            $request->query('to'),
            $this->branchId($request),
        ));
    }

    public function branchComplete(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);

        return $this->ok($this->reports->branchCompleteReport(
            (int) $request->query('branch_id'),
            $request->query('from'),
            $request->query('to'),
        ));
    }
}
