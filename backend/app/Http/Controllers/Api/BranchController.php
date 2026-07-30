<?php

namespace App\Http\Controllers\Api;

use App\Models\Branch;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BranchController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        // Lookup for sales/dashboard/reports selectors; manage stays on settings.manage.
        $this->authorizeAnyPermission([
            'settings.manage',
            'dashboard.view',
            'sales.view',
            'purchases.view',
            'reports.view',
            'warehouse.view',
            'cash.view',
        ]);

        $query = Branch::query()->with('company')->orderBy('code');
        ListSearch::apply($query, $request, ['code', 'name', 'city', 'address'], [
            'company' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('branches', 'code')->where(fn ($q) => $q->where('company_id', $request->integer('company_id'))),
            ],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(Branch::query()->create($data)->load('company'), 201);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('branches', 'code')
                    ->where(fn ($q) => $q->where('company_id', $request->integer('company_id')))
                    ->ignore($branch->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
        $branch->update($data);

        return $this->ok($branch->fresh('company'));
    }
}
