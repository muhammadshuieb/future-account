<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseReturn;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends ApiController
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok(PurchaseReturn::query()->with(['supplier', 'warehouse'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $request->validate([
            'return_date' => ['required', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_invoice_id' => ['nullable', 'exists:purchase_invoices,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->ok($this->purchases->createReturn($data, $data['lines'], $request->user()), 201);
    }

    public function post(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorizePermission('purchases.manage');

        return $this->ok($this->purchases->postReturn($purchaseReturn, request()->user()));
    }

    public function destroy(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $this->purchases->deleteReturn($purchaseReturn);

        return response()->json(['message' => 'تم حذف مرتجع المشتريات.']);
    }
}
