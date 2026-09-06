<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Services\ReportService;
use App\Support\SensitiveFinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends ApiController
{
    public function __construct(protected ReportService $reports) {}

    protected function branchId(Request $request): ?int
    {
        return $request->filled('branch_id') ? (int) $request->query('branch_id') : null;
    }

    protected function warehouseId(Request $request): ?int
    {
        return $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null;
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        $data = $this->reports->trialBalance($request->query('as_of'), $this->branchId($request));

        return $this->ok(SensitiveFinanceAccess::redactTrialBalance($data, $request->user()));
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $this->authorizePermission(SensitiveFinanceAccess::PROFITS);

        return $this->ok($this->reports->incomeStatement($request->query('from'), $request->query('to'), $this->branchId($request)));
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');

        $data = $this->reports->balanceSheet($request->query('as_of'), $this->branchId($request));

        return $this->ok(SensitiveFinanceAccess::redactBalanceSheet($data, $request->user()));
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

        return $this->ok($this->reports->inventoryReport($this->branchId($request), $this->warehouseId($request)));
    }

    public function profit(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $this->authorizePermission(SensitiveFinanceAccess::PROFITS);

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
            $this->warehouseId($request),
        ));
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $this->authorizePermission('reports.view');
        $request->validate(['account_id' => ['required', 'integer', 'exists:accounts,id']]);

        $account = Account::query()->findOrFail((int) $request->query('account_id'));
        SensitiveFinanceAccess::assertAccountVisible($request->user(), $account);

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

        $data = $this->reports->branchCompleteReport(
            (int) $request->query('branch_id'),
            $request->query('from'),
            $request->query('to'),
        );

        return $this->ok(SensitiveFinanceAccess::redactBranchComplete($data, $request->user()));
    }
}
