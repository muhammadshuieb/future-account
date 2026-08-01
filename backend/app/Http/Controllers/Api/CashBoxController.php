<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Models\CashBox;
use App\Services\CashBoxGlService;
use App\Services\CashService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CashBoxController extends ApiController
{
    public function __construct(
        protected CashService $cash,
        protected CashBoxGlService $cashBoxGl,
    ) {}

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
            'account_id' => ['required', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ]);

        $data['currency'] = strtoupper($data['currency'] ?? 'USD');
        $this->assertPostableCashAccount((int) $data['account_id']);
        $this->assertAccountNotUsedByOtherBox((int) $data['account_id']);

        if (! empty($data['is_default'])) {
            CashBox::query()->where('is_default', true)->update(['is_default' => false]);
        }

        $box = CashBox::query()->create($data)->load(['branch', 'account']);
        if ($box->is_default) {
            \App\Models\Setting::setValue('default_cash_box_id', (string) $box->id, 'cash', 'string', 'الصندوق الرئيسي الافتراضي');
        }
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
            'account_id' => ['required', 'exists:accounts,id'],
            'opening_balance' => ['numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ]);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $this->assertPostableCashAccount((int) $data['account_id']);
        $this->assertAccountNotUsedByOtherBox((int) $data['account_id'], $cashBox->id);

        if (! empty($data['is_default'])) {
            CashBox::query()->where('id', '!=', $cashBox->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $cashBox->update($data);
        $fresh = $cashBox->fresh(['branch', 'account']);
        if ($fresh->is_default) {
            \App\Models\Setting::setValue('default_cash_box_id', (string) $fresh->id, 'cash', 'string', 'الصندوق الرئيسي الافتراضي');
        }
        $fresh->setAttribute('balance', $this->cash->cashBoxCurrencyBalance($fresh));

        return $this->ok($fresh);
    }

    public function destroy(CashBox $cashBox): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $cashBox->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }

    /**
     * Suggest the default GL account for a currency (used by CashBanks UI).
     */
    public function suggestAccount(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.view');
        $data = $request->validate([
            'currency' => ['required', 'string', 'max:8'],
            'exclude_box_id' => ['nullable', 'integer', 'exists:cash_boxes,id'],
        ]);

        $this->cashBoxGl->ensureStandardCurrencyAccounts();
        $currency = strtoupper($data['currency']);
        $account = $this->cashBoxGl->ensureCurrencyAccount($currency);

        $taken = CashBox::query()
            ->where('account_id', $account->id)
            ->when(
                ! empty($data['exclude_box_id']),
                fn ($q) => $q->where('id', '!=', (int) $data['exclude_box_id'])
            )
            ->exists();

        if ($taken) {
            // Return metadata so UI can still show recommendation; client may create a new leaf.
            return $this->ok([
                'account' => null,
                'suggested_code' => null,
                'currency' => $currency,
                'message' => 'الحساب الافتراضي لهذه العملة مرتبط بصندوق آخر — اختر أو أنشئ حساباً مستقلاً.',
                'defaults' => CashBoxGlService::CURRENCY_DEFAULTS[$currency] ?? null,
            ]);
        }

        return $this->ok([
            'account' => $account,
            'suggested_code' => $account->code,
            'currency' => $currency,
            'message' => null,
            'defaults' => CashBoxGlService::CURRENCY_DEFAULTS[$currency] ?? null,
        ]);
    }

    protected function assertPostableCashAccount(int $accountId): void
    {
        $account = Account::query()->findOrFail($accountId);
        if ($account->is_group || ! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => ['يجب اختيار حساب محاسبي تفصيلي نشط (غير تجميعي).'],
            ]);
        }
        if ($account->type !== 'asset') {
            throw ValidationException::withMessages([
                'account_id' => ['حساب الصندوق يجب أن يكون من نوع أصول.'],
            ]);
        }
    }

    protected function assertAccountNotUsedByOtherBox(int $accountId, ?int $exceptBoxId = null): void
    {
        $taken = CashBox::query()
            ->where('account_id', $accountId)
            ->when($exceptBoxId, fn ($q) => $q->where('id', '!=', $exceptBoxId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'account_id' => ['هذا الحساب المحاسبي مرتبط بصندوق آخر. لكل صندوق حساب مستقل لترحيل الصرف.'],
            ]);
        }
    }
}
