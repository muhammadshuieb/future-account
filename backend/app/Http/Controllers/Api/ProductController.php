<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = Product::query()->with(['category', 'unit'])->withSum('stockLevels as on_hand', 'quantity')->orderBy('sku');
        if ($request->filled('barcode')) {
            $query->where('barcode', $request->string('barcode'));
        } elseif ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")->orWhere('barcode', 'like', "%{$s}%"));
        }

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        return $this->ok(Product::query()->create($this->validated($request))->load(['category', 'unit']), 201);
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

        $query = StockLevel::query()
            ->where('warehouse_id', $data['warehouse_id'])
            ->where('product_id', $product->id);

        if (array_key_exists('batch_no', $data) && $data['batch_no'] !== null && $data['batch_no'] !== '') {
            $query->where('batch_no', $data['batch_no']);
        }

        $availableQty = round((float) $query->sum('quantity'), 3);

        return $this->ok([
            'product_id' => $product->id,
            'warehouse_id' => (int) $data['warehouse_id'],
            'available_qty' => max(0, $availableQty),
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
        $product->delete();

        return response()->json(['message' => 'تم الحذف.']);
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
