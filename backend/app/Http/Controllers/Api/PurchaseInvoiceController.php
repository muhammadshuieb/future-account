<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseInvoice;
use App\Services\PurchaseService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseInvoiceController extends ApiController
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('purchases.view');
        $query = PurchaseInvoice::query()->with(['supplier', 'warehouse'])->withCount('attachments')->latest('id');
        ListSearch::apply($query, $request, ['invoice_number', 'notes', 'currency', 'total', 'status'], [
            'supplier' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $request->validate([
            'invoice_date' => ['required', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'cash_box_id' => ['nullable', 'exists:cash_boxes,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payment_type' => ['nullable', 'in:cash,credit,partial'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'customs_amount' => ['nullable', 'numeric', 'min:0'],
            'transport_fees' => ['nullable', 'numeric', 'min:0'],
            'fines_amount' => ['nullable', 'numeric', 'min:0'],
            'other_fees' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->ok($this->purchases->createInvoice($data, $data['lines'], $request->user()), 201);
    }

    public function show(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok($purchaseInvoice->load(['supplier', 'warehouse', 'cashBox', 'lines.product.unit', 'journalEntry', 'attachments']));
    }

    public function post(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorizePermission('purchases.manage');

        return $this->ok($this->purchases->postInvoice($purchaseInvoice, request()->user()));
    }

    public function payRemaining(Request $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $request->validate([
            'payment_date' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'cash_box_id' => ['nullable', 'exists:cash_boxes,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'method' => ['nullable', 'in:cash,bank'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'base_amount' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $payment = $this->purchases->payRemaining($purchaseInvoice, $request->user(), $data);

        return $this->ok([
            'payment' => $payment,
            'invoice' => $purchaseInvoice->fresh(['supplier', 'cashBox']),
        ], 201);
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $this->purchases->deleteInvoice($purchaseInvoice);

        return response()->json(['message' => 'تم حذف فاتورة المشتريات.']);
    }
}
