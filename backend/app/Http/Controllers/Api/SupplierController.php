<?php

namespace App\Http\Controllers\Api;

use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends ApiController
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('suppliers.view');
        $query = Supplier::query()->with('branch')->orderBy('code');
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('suppliers.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:suppliers,code'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'credit_limit' => ['numeric', 'min:0'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(Supplier::query()->create($data)->load('branch'), 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $this->authorizePermission('suppliers.view');

        return $this->ok($supplier->load('branch'));
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorizePermission('suppliers.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:suppliers,code,'.$supplier->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'credit_limit' => ['numeric', 'min:0'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
        $supplier->update($data);

        return $this->ok($supplier->fresh('branch'));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->authorizePermission('suppliers.manage');
        $supplier->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }

    public function statement(Supplier $supplier): JsonResponse
    {
        $this->authorizePermission('suppliers.view');

        return $this->ok($this->purchases->supplierStatement($supplier));
    }
}
