<?php

namespace App\Http\Controllers\Api;

use App\Models\SupplierPayment;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierPaymentController extends ApiController
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok(SupplierPayment::query()->with(['supplier', 'invoice'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_invoice_id' => ['nullable', 'exists:purchase_invoices,id'],
            'cash_box_id' => ['nullable', 'exists:cash_boxes,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'method' => ['required', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        return $this->ok($this->purchases->createPayment($data, $request->user()), 201);
    }

    public function post(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->authorizePermission('purchases.manage');

        return $this->ok($this->purchases->postPayment($supplierPayment, request()->user()));
    }

    public function destroy(SupplierPayment $supplierPayment): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $this->purchases->deletePayment($supplierPayment);

        return response()->json(['message' => 'تم حذف سند الدفع.']);
    }
}
