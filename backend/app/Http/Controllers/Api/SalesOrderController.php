<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesOrder;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesOrderController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok(SalesOrder::query()->with(['customer', 'warehouse', 'quote'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $this->validated($request);

        return $this->ok($this->sales->createOrder($data, $data['lines'], $request->user()), 201);
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok($salesOrder->load(['customer', 'warehouse', 'items.product', 'quote']));
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        if ($salesOrder->status === 'converted') {
            abort(422, 'لا يمكن حذف أمر محوّل.');
        }
        $salesOrder->items()->delete();
        $salesOrder->delete();

        return $this->ok(['deleted' => true]);
    }

    public function convertToInvoice(Request $request, SalesOrder $salesOrder): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $overrides = $request->validate([
            'invoice_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        return $this->ok($this->sales->convertOrderToInvoice($salesOrder, $request->user(), $overrides), 201);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'order_date' => ['required', 'date'],
            'customer_id' => ['required', 'exists:customers,id'],
            'sales_quote_id' => ['nullable', 'exists:sales_quotes,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,confirmed,cancelled'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);
    }
}
