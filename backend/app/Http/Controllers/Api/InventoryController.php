<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryCount;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\WarehouseTransfer;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends ApiController
{
    public function __construct(protected InventoryService $inventory) {}

    public function stockLevels(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = StockLevel::query()->with(['warehouse', 'product'])->orderByDesc('updated_at');
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }

        return $this->ok($query->get());
    }

    public function movements(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = StockMovement::query()->with(['warehouse', 'product'])->latest('id');
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        return $this->ok($query->limit(200)->get());
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'type' => ['required', 'in:in,out,adjustment'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'movement_date' => ['nullable', 'date'],
            'batch_no' => ['nullable', 'string', 'max:64'],
            'serial_no' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'post_to_gl' => ['boolean'],
        ]);

        return $this->ok($this->inventory->createManualMovement($data, $request->user())->load(['warehouse', 'product']), 201);
    }

    public function alerts(): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->ok($this->inventory->lowStockAlerts());
    }

    public function transfers(): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->ok(WarehouseTransfer::query()->with(['fromWarehouse', 'toWarehouse', 'lines.product'])->latest('id')->get());
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'from_warehouse_id' => ['required', 'exists:warehouses,id', 'different:to_warehouse_id'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return $this->ok($this->inventory->transfer($data, $data['lines'], $request->user()), 201);
    }

    public function postTransfer(WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        return $this->ok($this->inventory->postTransfer($warehouseTransfer, request()->user()));
    }

    public function counts(): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->ok(InventoryCount::query()->with(['warehouse', 'lines.product'])->latest('id')->get());
    }

    public function storeCount(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'count_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.counted_qty' => ['required', 'numeric'],
        ]);

        $count = InventoryCount::query()->create([
            'count_number' => $this->inventory->nextNumber('CNT'),
            'warehouse_id' => $data['warehouse_id'],
            'count_date' => $data['count_date'],
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['lines'] as $line) {
            $system = (float) (StockLevel::query()
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('product_id', $line['product_id'])
                ->value('quantity') ?? 0);
            $counted = (float) $line['counted_qty'];
            $count->lines()->create([
                'product_id' => $line['product_id'],
                'system_qty' => $system,
                'counted_qty' => $counted,
                'difference' => round($counted - $system, 3),
            ]);
        }

        return $this->ok($count->load(['warehouse', 'lines.product']), 201);
    }

    public function postCount(InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');

        return $this->ok($this->inventory->postInventoryCount($inventoryCount, request()->user()));
    }
}
