<?php

namespace App\Http\Controllers\Api;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('settings.manage');

        return $this->ok(Branch::query()->with('company')->orderBy('code')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('settings.manage');
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'code' => ['required', 'string', 'max:32'],
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
        $branch->update($request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'is_main' => ['boolean'],
            'is_active' => ['boolean'],
        ]));

        return $this->ok($branch->fresh('company'));
    }
}
