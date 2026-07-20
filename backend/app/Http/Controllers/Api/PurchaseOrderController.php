<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseOrder;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends ApiController
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok(PurchaseOrder::query()->with(['supplier', 'warehouse', 'request'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $this->validated($request);

        return $this->ok($this->purchases->createOrder($data, $data['lines'], $request->user()), 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok($purchaseOrder->load(['supplier', 'warehouse', 'items.product', 'request']));
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        if ($purchaseOrder->status === 'converted') {
            abort(422, 'لا يمكن حذف أمر محوّل.');
        }
        $purchaseOrder->items()->delete();
        $purchaseOrder->delete();

        return $this->ok(['deleted' => true]);
    }

    public function convertToInvoice(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $overrides = $request->validate([
            'invoice_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        return $this->ok($this->purchases->convertOrderToInvoice($purchaseOrder, $request->user(), $overrides), 201);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'order_date' => ['required', 'date'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'purchase_request_id' => ['nullable', 'exists:purchase_requests,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,confirmed,cancelled'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);
    }
}
