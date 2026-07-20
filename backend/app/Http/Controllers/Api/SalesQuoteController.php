<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesQuote;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesQuoteController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok(SalesQuote::query()->with(['customer', 'warehouse'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $this->validated($request);

        return $this->ok($this->sales->createQuote($data, $data['lines'], $request->user()), 201);
    }

    public function show(SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok($salesQuote->load(['customer', 'warehouse', 'items.product']));
    }

    public function update(Request $request, SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $this->validated($request);

        return $this->ok($this->sales->updateQuote($salesQuote, $data, $data['lines'], $request->user()));
    }

    public function destroy(SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        if ($salesQuote->status === 'converted') {
            abort(422, 'لا يمكن حذف عرض محوّل.');
        }
        $salesQuote->items()->delete();
        $salesQuote->delete();

        return $this->ok(['deleted' => true]);
    }

    public function convertToOrder(Request $request, SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $overrides = $request->validate([
            'order_date' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
        ]);

        return $this->ok($this->sales->convertQuoteToOrder($salesQuote, $request->user(), $overrides), 201);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'customer_id' => ['required', 'exists:customers,id'],
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
