<?php

namespace App\Http\Controllers\Api;

use App\Models\Bank;
use App\Services\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(Bank::query()->with(['branch', 'account'])->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:banks,code'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'iban' => ['nullable', 'string', 'max:64'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(Bank::query()->create($data)->load(['branch', 'account']), 201);
    }

    public function update(Request $request, Bank $bank): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $bank->update($request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:banks,code,'.$bank->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'iban' => ['nullable', 'string', 'max:64'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]));

        return $this->ok($bank->fresh(['branch', 'account']));
    }

    public function destroy(Bank $bank): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $bank->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }

    public function bookBalance(Bank $bank): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(['book_balance' => $this->cash->bookBalance($bank)]);
    }
}
