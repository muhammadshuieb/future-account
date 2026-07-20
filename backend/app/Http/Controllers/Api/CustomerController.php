<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('customers.view');
        $query = Customer::query()->with('branch')->orderBy('code');
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('customers.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:customers,code'],
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

        return $this->ok(Customer::query()->create($data)->load('branch'), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->authorizePermission('customers.view');

        return $this->ok($customer->load('branch'));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePermission('customers.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:customers,code,'.$customer->id],
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
        $customer->update($data);

        return $this->ok($customer->fresh('branch'));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->authorizePermission('customers.manage');
        $customer->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }

    public function statement(Customer $customer): JsonResponse
    {
        $this->authorizePermission('customers.view');

        return $this->ok($this->sales->customerStatement($customer));
    }
}
