<?php

namespace App\Http\Controllers\Api;

use App\Models\Branch;
use App\Models\Company;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $query = Company::query()->with('branches')->orderBy('code');
        ListSearch::apply($query, $request, ['code', 'name', 'name_en', 'tax_number', 'currency']);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:companies,code'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(Company::query()->create($data), 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $company->update($request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:companies,code,'.$company->id],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]));

        return $this->ok($company->fresh('branches'));
    }
}
