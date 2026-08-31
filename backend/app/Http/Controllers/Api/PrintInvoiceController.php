<?php

namespace App\Http\Controllers\Api;

use App\Models\PrintInvoice;
use App\Services\InventoryService;
use App\Services\SalesService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintInvoiceController extends ApiController
{
    public function __construct(
        protected SalesService $sales,
        protected InventoryService $inventory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePrintInvoiceView();
        $query = PrintInvoice::query()->with(['customer', 'warehouse', 'branch'])->latest('id');
        ListSearch::apply($query, $request, ['invoice_number', 'notes', 'currency', 'total', 'status'], [
            'customer' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePrintInvoiceManage();
        $data = $this->validated($request);

        return $this->ok($this->sales->createPrintInvoice($data, $data['lines'], $request->user()), 201);
    }

    public function show(PrintInvoice $printInvoice): JsonResponse
    {
        $this->authorizePrintInvoiceView();

        $invoice = $printInvoice->load(['customer', 'warehouse', 'branch', 'items.product.unit']);
        $warnings = $this->buildStockWarnings(
            $invoice->items->all(),
            $invoice->warehouse_id ? (int) $invoice->warehouse_id : null,
        );

        return $this->ok(array_merge($invoice->toArray(), ['stock_warnings' => $warnings]));
    }

    public function update(Request $request, PrintInvoice $printInvoice): JsonResponse
    {
        $this->authorizePrintInvoiceManage();
        $data = $this->validated($request);

        return $this->ok($this->sales->updatePrintInvoice($printInvoice, $data, $data['lines']));
    }

    public function destroy(PrintInvoice $printInvoice): JsonResponse
    {
        $this->authorizePrintInvoiceManage();
        $this->sales->deletePrintInvoice($printInvoice);

        return response()->json(['message' => 'تم حذف فاتورة الطباعة.']);
    }

    public function previewStockWarnings(Request $request): JsonResponse
    {
        $this->authorizePrintInvoiceView();
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

    protected function authorizePrintInvoiceView(): void
    {
        $this->authorizeAnyPermission(['print_invoices.view', 'sales.view']);
    }

    protected function authorizePrintInvoiceManage(): void
    {
        $this->authorizeAnyPermission(['print_invoices.manage', 'sales.manage']);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'invoice_date' => ['required', 'date'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,cancelled'],
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
