<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesQuote;
use App\Services\InventoryService;
use App\Services\SalesService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesQuoteController extends ApiController
{
    public function __construct(
        protected SalesService $sales,
        protected InventoryService $inventory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeQuoteView();
        $query = SalesQuote::query()->with(['customer', 'warehouse', 'branch'])->latest('id');
        ListSearch::apply($query, $request, ['quote_number', 'notes', 'currency', 'total', 'status'], [
            'customer' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeQuoteManage();
        $data = $this->validated($request);

        return $this->ok($this->sales->createQuote($data, $data['lines'], $request->user()), 201);
    }

    public function show(SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizeQuoteView();

        $quote = $salesQuote->load(['customer', 'warehouse', 'branch', 'items.product.unit']);
        $warnings = $this->buildStockWarnings($quote->items->all(), $quote->warehouse_id ? (int) $quote->warehouse_id : null);

        return $this->ok(array_merge($quote->toArray(), ['stock_warnings' => $warnings]));
    }

    public function update(Request $request, SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizeQuoteManage();
        $data = $this->validated($request);

        return $this->ok($this->sales->updateQuote($salesQuote, $data, $data['lines'], $request->user()));
    }

    public function destroy(SalesQuote $salesQuote): JsonResponse
    {
        $this->authorizeQuoteManage();
        $this->sales->deleteQuote($salesQuote);

        return response()->json(['message' => 'تم حذف عرض السعر.']);
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

    /**
     * On-screen stock hints for quote lines (never blocks save/print; quotes do not reserve stock).
     */
    public function previewStockWarnings(Request $request): JsonResponse
    {
        $this->authorizeQuoteView();
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.product_name' => ['nullable', 'string'],
        ]);

        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        $lines = collect($data['lines'])->map(fn (array $line) => (object) [
            'product_id' => (int) $line['product_id'],
            'quantity' => (float) $line['quantity'],
            'product' => (object) ['name' => $line['product_name'] ?? null],
        ])->all();

        return $this->ok($this->buildStockWarnings($lines, $warehouseId));
    }

    /**
     * @param  list<object|array>  $items
     * @return list<array{product_id:int,product_name:?string,quantity:float,available_qty:float,code:string,message:string}>
     */
    protected function buildStockWarnings(array $items, ?int $warehouseId): array
    {
        $warnings = [];

        foreach ($items as $item) {
            $productId = (int) (is_array($item) ? ($item['product_id'] ?? 0) : ($item->product_id ?? 0));
            if ($productId <= 0) {
                continue;
            }
            $qty = (float) (is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0));
            $name = is_array($item)
                ? ($item['product']['name'] ?? $item['product_name'] ?? null)
                : ($item->product->name ?? null);

            $breakdown = $this->inventory->stockBreakdown($productId, $warehouseId);
            $available = round(array_sum(array_column($breakdown, 'quantity')), 3);

            if ($available <= 0) {
                $warnings[] = [
                    'product_id' => $productId,
                    'product_name' => $name,
                    'quantity' => $qty,
                    'available_qty' => 0.0,
                    'code' => 'no_stock',
                    'message' => $warehouseId
                        ? 'لا يوجد رصيد لهذا الصنف في المستودع المحدد.'
                        : 'لا يوجد رصيد لهذا الصنف في أي مستودع.',
                ];
            } elseif ($qty > $available + 0.0001) {
                $warnings[] = [
                    'product_id' => $productId,
                    'product_name' => $name,
                    'quantity' => $qty,
                    'available_qty' => $available,
                    'code' => 'qty_exceeds_stock',
                    'message' => "الكمية المطلوبة ({$qty}) أكبر من المتاح ({$available}).",
                ];
            }
        }

        return $warnings;
    }

    protected function authorizeQuoteView(): void
    {
        $this->authorizeAnyPermission(['quotes.view', 'sales.view']);
    }

    protected function authorizeQuoteManage(): void
    {
        $this->authorizeAnyPermission(['quotes.manage', 'sales.manage']);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'exists:customers,id'],
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
