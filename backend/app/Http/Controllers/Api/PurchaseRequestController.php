<?php

namespace App\Http\Controllers\Api;

use App\Models\PurchaseRequest;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseRequestController extends ApiController
{
    public function __construct(protected PurchaseService $purchases) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok(PurchaseRequest::query()->with(['supplier', 'warehouse'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $this->validated($request);

        return $this->ok($this->purchases->createRequest($data, $data['lines'], $request->user()), 201);
    }

    public function show(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorizePermission('purchases.view');

        return $this->ok($purchaseRequest->load(['supplier', 'warehouse', 'items.product']));
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $data = $this->validated($request);

        return $this->ok($this->purchases->updateRequest($purchaseRequest, $data, $data['lines']));
    }

    public function destroy(PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $this->purchases->deleteRequest($purchaseRequest);

        return response()->json(['message' => 'تم حذف طلب الشراء.']);
    }

    public function convertToOrder(Request $request, PurchaseRequest $purchaseRequest): JsonResponse
    {
        $this->authorizePermission('purchases.manage');
        $overrides = $request->validate([
            'order_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
        ]);

        return $this->ok($this->purchases->convertRequestToOrder($purchaseRequest, $request->user(), $overrides), 201);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'request_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,approved,cancelled'],
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
