<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryCountLine;
use App\Models\Product;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequestItem;
use App\Models\PurchaseReturnLine;
use App\Models\SalesInvoiceLine;
use App\Models\SalesOrderItem;
use App\Models\SalesQuoteItem;
use App\Models\SalesReturnLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\WarehouseTransferLine;
use App\Services\InventoryService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends ApiController
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = Product::query()
            ->with([
                'category',
                'unit',
                'stockLevels' => fn ($q) => $q->where('quantity', '>', 0)->with('warehouse:id,name'),
            ])
            ->withSum('stockLevels as on_hand', 'quantity')
            ->orderBy('sku');
        if ($request->filled('barcode')) {
            $query->where('barcode', $request->string('barcode'));
        } else {
        ListSearch::apply($query, $request, ['name', 'sku', 'barcode']);
        }

        $products = $query->get()->map(function (Product $product) {
            $data = $product->toArray();
            $data['stock_locations'] = $product->stockLevels
                ->map(fn (StockLevel $level) => [
                    'warehouse_id' => (int) $level->warehouse_id,
                    'warehouse_name' => $level->warehouse?->name ?? '—',
                    'batch_no' => (string) ($level->batch_no ?? ''),
                    'quantity' => round((float) $level->quantity, 3),
                ])
                ->values()
                ->all();
            unset($data['stock_levels']);

            return $data;
        });

        return $this->ok($products);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        $data = $this->validated($request);
        $stock = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'opening_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = DB::transaction(function () use ($request, $data, $stock) {
            $product = Product::query()->create($data);
            $warehouseId = (int) $stock['warehouse_id'];
            $openingQty = round((float) ($stock['opening_quantity'] ?? 0), 3);

            if ($openingQty > 0) {
                $this->inventory->adjustStock(
                    $warehouseId,
                    $product->id,
                    $openingQty,
                    'in',
                    $request->user(),
                    [
                        'unit_cost' => $product->cost_price,
                        'notes' => 'رصيد افتتاحي عند إنشاء الصنف',
                    ]
                );
            } else {
                // Register the product in the chosen warehouse with zero qty so it appears in stock views.
                StockLevel::query()->firstOrCreate(
                    [
                        'warehouse_id' => $warehouseId,
                        'product_id' => $product->id,
                        'batch_no' => '',
                    ],
                    ['quantity' => 0]
                );
            }

            return $product;
        });

        return $this->ok($product->load(['category', 'unit', 'stockLevels.warehouse']), 201);
    }

    public function show(Product $product): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->ok($product->load(['category', 'unit', 'stockLevels.warehouse']));
    }

    public function stock(Request $request, Product $product): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'batch_no' => ['nullable', 'string', 'max:64'],
        ]);

        $warehouseId = (int) $data['warehouse_id'];
        $batchNo = $data['batch_no'] ?? null;
        $availableQty = $this->inventory->availableQty($warehouseId, $product->id, $batchNo, $product);
        $breakdown = $this->inventory->stockBreakdown($product->id, $warehouseId);
        $warehouseName = $breakdown[0]['warehouse_name'] ?? \App\Models\Warehouse::query()->find($warehouseId)?->name;

        return $this->ok([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'warehouse_name' => $warehouseName,
            'batch_no' => $batchNo,
            'available_qty' => max(0, $availableQty),
            'breakdown' => $breakdown,
            'track_batch' => (bool) $product->track_batch,
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $product->update($this->validated($request, $product->id));

        return $this->ok($product->fresh(['category', 'unit']));
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        $inUse =
            StockMovement::query()->where('product_id', $product->id)->exists()
            || StockLevel::query()->where('product_id', $product->id)->where('quantity', '!=', 0)->exists()
            || SalesInvoiceLine::query()->where('product_id', $product->id)->exists()
            || SalesReturnLine::query()->where('product_id', $product->id)->exists()
            || SalesQuoteItem::query()->where('product_id', $product->id)->exists()
            || SalesOrderItem::query()->where('product_id', $product->id)->exists()
            || PurchaseInvoiceLine::query()->where('product_id', $product->id)->exists()
            || PurchaseReturnLine::query()->where('product_id', $product->id)->exists()
            || PurchaseRequestItem::query()->where('product_id', $product->id)->exists()
            || PurchaseOrderItem::query()->where('product_id', $product->id)->exists()
            || WarehouseTransferLine::query()->where('product_id', $product->id)->exists()
            || InventoryCountLine::query()->where('product_id', $product->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages([
                'product' => ['لا يمكن حذف الصنف لأنه مستخدم في فواتير/حركات مخزون'],
            ]);
        }

        // Zero-qty stock levels (if any) cascade; remove explicitly for clarity.
        StockLevel::query()->where('product_id', $product->id)->delete();
        $product->delete();

        return response()->json(['message' => 'تم حذف الصنف.']);
    }

    protected function validated(Request $request, ?int $id = null): array
    {
        $idPart = $id ?? 'NULL';

        return $request->validate([
            'sku' => ['required', 'string', 'max:64', "unique:products,sku,{$idPart}"],
            'barcode' => ['nullable', 'string', 'max:64', "unique:products,barcode,{$idPart}"],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'cost_price' => ['numeric', 'min:0'],
            'sale_price' => ['numeric', 'min:0'],
            'reorder_level' => ['numeric', 'min:0'],
            'track_batch' => ['boolean'],
            'track_serial' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }
}
