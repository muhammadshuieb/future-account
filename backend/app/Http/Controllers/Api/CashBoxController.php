<?php

namespace App\Http\Controllers\Api;

use App\Models\CashBox;
use App\Services\CashService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashBoxController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.view');

        $query = CashBox::query()->with(['branch', 'account'])->orderBy('code');
        ListSearch::apply($query, $request, ['name', 'code', 'currency'], [
            'branch' => ['name', 'code'],
        ]);
        $boxes = $query->get()
            ->map(function (CashBox $box) {
                $box->setAttribute('balance', $this->cash->cashBoxCurrencyBalance($box));

                return $box;
            });

        return $this->ok($boxes);
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
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);

        $data['currency'] = strtoupper($data['currency'] ?? 'SYP');

        $box = CashBox::query()->create($data)->load(['branch', 'account']);
        $box->setAttribute('balance', $this->cash->cashBoxCurrencyBalance($box));

        return $this->ok($box, 201);
    }

    public function update(Request $request, CashBox $cashBox): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:cash_boxes,code,'.$cashBox->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $cashBox->update($data);
        $fresh = $cashBox->fresh(['branch', 'account']);
        $fresh->setAttribute('balance', $this->cash->cashBoxCurrencyBalance($fresh));

        return $this->ok($fresh);
    }

    public function destroy(CashBox $cashBox): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $cashBox->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
