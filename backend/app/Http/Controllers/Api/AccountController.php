<?php

namespace App\Http\Controllers\Api;

use App\Models\Account;
use App\Support\SensitiveFinanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('accounts.view');

        $canCapital = SensitiveFinanceAccess::canViewCapital($request->user());

        if ($request->boolean('tree')) {
            $accounts = Account::query()
                ->with(['children' => fn ($q) => $q->orderBy('code')])
                ->roots()
                ->orderBy('code')
                ->get();

            if (! $canCapital) {
                $accounts = $accounts->reject(fn (Account $a) => $a->type === 'equity')->values();
            }

            return $this->ok($this->buildTree($accounts, $canCapital));
        }

        $query = Account::query()->with('parent:id,code,name')->orderBy('code');

        if (! $canCapital) {
            $query->where('type', '!=', 'equity');
        }

        if ($request->filled('type')) {
            $type = (string) $request->string('type');
            if ($type === 'equity' && ! $canCapital) {
                abort(403, 'ليس لديك صلاحية.');
            }
            $query->where('type', $type);
        }

        if ($request->boolean('postable_only')) {
            $query->where('is_group', false)->where('is_active', true);
        }

        \App\Support\ListSearch::apply($query, $request, ['code', 'name', 'name_en', 'description']);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('accounts.manage');
        $data = $this->validated($request);
        if (($data['type'] ?? null) === 'equity') {
            $this->authorizePermission(SensitiveFinanceAccess::CAPITAL);
        }
        $data = $this->applyHierarchy($data);

        $account = Account::query()->create($data);

        return response()->json(['data' => $account->load('parent')], 201);
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission('accounts.view');
        SensitiveFinanceAccess::assertAccountVisible($request->user(), $account);

        return $this->ok($account->load(['parent', 'children']));
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission('accounts.manage');
        SensitiveFinanceAccess::assertAccountVisible($request->user(), $account);

        $data = $this->validated($request, $account->id);
        if (($data['type'] ?? null) === 'equity' || $account->type === 'equity') {
            $this->authorizePermission(SensitiveFinanceAccess::CAPITAL);
        }

        if (isset($data['parent_id']) && (int) $data['parent_id'] === $account->id) {
            return response()->json(['message' => 'لا يمكن أن يكون الحساب أباً لنفسه.'], 422);
        }

        $data = $this->applyHierarchy($data);
        $account->update($data);

        return $this->ok($account->fresh(['parent', 'children']));
    }

    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission('accounts.manage');
        SensitiveFinanceAccess::assertAccountVisible($request->user(), $account);

        if ($account->children()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف حساب له حسابات فرعية.'], 422);
        }

        if ($account->journalDetails()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف حساب مرتبط بقيود.'], 422);
        }

        $account->delete();

        return response()->json(['message' => 'تم حذف الحساب.']);
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('accounts', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:accounts,id'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'nature' => ['required', Rule::in(['debit', 'credit'])],
            'is_group' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string'],
        ]);
    }

    protected function applyHierarchy(array $data): array
    {
        if (! empty($data['parent_id'])) {
            $parent = Account::query()->findOrFail($data['parent_id']);
            $data['level'] = $parent->level + 1;
            $data['type'] = $data['type'] ?? $parent->type;
        } else {
            $data['level'] = 1;
        }

        $data['is_group'] = $data['is_group'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }

    protected function buildTree($accounts, bool $canCapital = true): array
    {
        return $accounts->map(function (Account $account) use ($canCapital) {
            $children = $account->children()->with('children')->orderBy('code')->get();
            if (! $canCapital) {
                $children = $children->reject(fn (Account $c) => $c->type === 'equity')->values();
            }

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'name_en' => $account->name_en,
                'type' => $account->type,
                'nature' => $account->nature,
                'level' => $account->level,
                'is_group' => $account->is_group,
                'is_active' => $account->is_active,
                'children' => $this->buildTree($children, $canCapital),
            ];
        })->values()->all();
    }
}
