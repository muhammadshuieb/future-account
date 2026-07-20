<?php

namespace App\Http\Controllers\Api;

use App\Models\Bank;
use App\Models\BankReconciliation;
use App\Models\CashBox;
use App\Models\CashTransfer;
use App\Services\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBoxController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(CashBox::query()->with(['branch', 'account'])->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:cash_boxes,code'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(CashBox::query()->create($data)->load(['branch', 'account']), 201);
    }

    public function update(Request $request, CashBox $cashBox): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $cashBox->update($request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:cash_boxes,code,'.$cashBox->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'is_active' => ['boolean'],
        ]));

        return $this->ok($cashBox->fresh(['branch', 'account']));
    }

    public function destroy(CashBox $cashBox): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $cashBox->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
