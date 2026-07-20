<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Account::query()->with('parent:id,code,name')->orderBy('code');

        if ($request->boolean('tree')) {
            $accounts = Account::query()
                ->with(['children' => fn ($q) => $q->orderBy('code')])
                ->roots()
                ->orderBy('code')
                ->get();

            return response()->json(['data' => $this->buildTree($accounts)]);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->boolean('postable_only')) {
            $query->where('is_group', false)->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data = $this->applyHierarchy($data);

        $account = Account::query()->create($data);

        return response()->json(['data' => $account->load('parent')], 201);
    }

    public function show(Account $account): JsonResponse
    {
        return response()->json([
            'data' => $account->load(['parent', 'children']),
        ]);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $data = $this->validated($request, $account->id);

        if (isset($data['parent_id']) && (int) $data['parent_id'] === $account->id) {
            return response()->json(['message' => 'لا يمكن أن يكون الحساب أباً لنفسه.'], 422);
        }

        $data = $this->applyHierarchy($data);
        $account->update($data);

        return response()->json(['data' => $account->fresh(['parent', 'children'])]);
    }

    public function destroy(Account $account): JsonResponse
    {
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

    protected function buildTree($accounts): array
    {
        return $accounts->map(function (Account $account) {
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
                'children' => $this->buildTree(
                    $account->children()->with('children')->orderBy('code')->get()
                ),
            ];
        })->values()->all();
    }
}
