<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryCount;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\WarehouseTransfer;
use App\Services\InventoryService;
use App\Services\WarehouseApprovalService;
use App\Support\ListSearch;
use App\Support\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends ApiController
{
    public function __construct(
        protected InventoryService $inventory,
        protected WarehouseApprovalService $approvals,
    ) {}

    public function stockLevels(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = StockLevel::query()->with(['warehouse', 'product'])->orderByDesc('updated_at');
        WarehouseAccess::scopeWarehouseColumn($query, $request->user());
        if ($request->filled('warehouse_id')) {
            WarehouseAccess::assertWarehouse($request->user(), $request->integer('warehouse_id'));
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        ListSearch::apply($query, $request, ['batch_no'], [
            'product' => ['name', 'sku', 'barcode'],
            'warehouse' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function movements(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = StockMovement::query()->with(['warehouse', 'product'])->latest('id');
        WarehouseAccess::scopeWarehouseColumn($query, $request->user());
        if ($request->filled('warehouse_id')) {
            WarehouseAccess::assertWarehouse($request->user(), $request->integer('warehouse_id'));
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }
        ListSearch::apply($query, $request, ['reference', 'notes', 'type', 'batch_no', 'serial_no'], [
            'product' => ['name', 'sku', 'barcode'],
            'warehouse' => ['name', 'code'],
        ]);

        return $this->ok($query->limit(200)->get());
    }

    public function storeMovement(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.adjustments.request']);
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
        WarehouseAccess::assertWarehouse($request->user(), (int) $data['warehouse_id']);
        if (WarehouseAccess::isScoped($request->user())) {
            $approval = $this->approvals->request(
                $request->user(),
                'warehouse.adjustments.request',
                'stock.adjustment.create',
                $data,
                [(int) $data['warehouse_id']],
            );

            return $this->ok(['pending_approval' => true, 'approval_request' => $approval], 202);
        }

        return $this->ok($this->inventory->createManualMovement($data, $request->user())->load(['warehouse', 'product']), 201);
    }

    public function alerts(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;
        if ($warehouseId !== null) {
            WarehouseAccess::assertWarehouse($request->user(), $warehouseId);
        }
        if (WarehouseAccess::isScoped($request->user()) && $warehouseId === null) {
            $alerts = [];
            foreach (WarehouseAccess::warehouseIds($request->user()) as $assignedId) {
                array_push($alerts, ...$this->inventory->lowStockAlerts($assignedId));
            }

            return $this->ok($alerts);
        }

        return $this->ok($this->inventory->lowStockAlerts($warehouseId));
    }

    public function transfers(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = WarehouseTransfer::query()->with([
            'fromWarehouse:id,name,code',
            'toWarehouse:id,name,code',
            'lines.product',
        ])->latest('id');
        if (WarehouseAccess::isScoped($request->user())) {
            $ids = WarehouseAccess::warehouseIds($request->user());
            $query->whereIn('from_warehouse_id', $ids);
        }
        ListSearch::apply($query, $request, ['transfer_number', 'notes', 'status'], [
            'fromWarehouse' => ['name', 'code'],
            'toWarehouse' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.transfers.request']);
        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'from_warehouse_id' => ['required', 'exists:warehouses,id', 'different:to_warehouse_id'],
            'to_warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);
        WarehouseAccess::assertWarehouse($request->user(), (int) $data['from_warehouse_id']);
        if (WarehouseAccess::isScoped($request->user())) {
            $approval = $this->approvals->request(
                $request->user(),
                'warehouse.transfers.request',
                'warehouse_transfer.create',
                $data,
                [(int) $data['from_warehouse_id']],
            );

            return $this->ok(['pending_approval' => true, 'approval_request' => $approval], 202);
        }

        return $this->ok($this->inventory->transfer($data, $data['lines'], $request->user()), 201);
    }

    public function postTransfer(WarehouseTransfer $warehouseTransfer): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.transfers.request']);
        WarehouseAccess::assertWarehouse(request()->user(), (int) $warehouseTransfer->from_warehouse_id);
        if (! $warehouseTransfer->toWarehouse()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'to_warehouse_id' => ['يجب اختيار مخزن هدف نشط.'],
            ]);
        }
        if (WarehouseAccess::isScoped(request()->user())) {
            $approval = $this->approvals->request(
                request()->user(),
                'warehouse.transfers.request',
                'warehouse_transfer.post',
                array_merge(
                    $warehouseTransfer->load('lines')->toArray(),
                    ['requested_status' => 'posted'],
                ),
                [(int) $warehouseTransfer->from_warehouse_id],
                $warehouseTransfer,
            );

            return $this->ok(['pending_approval' => true, 'approval_request' => $approval], 202);
        }

        return $this->ok($this->inventory->postTransfer($warehouseTransfer, request()->user()));
    }

    public function counts(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = InventoryCount::query()->with(['warehouse', 'lines.product'])->latest('id');
        WarehouseAccess::scopeWarehouseColumn($query, $request->user());
        ListSearch::apply($query, $request, ['count_number', 'notes', 'status'], [
            'warehouse' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function storeCount(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.counts.request']);
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'count_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.counted_qty' => ['required', 'numeric'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);
        WarehouseAccess::assertWarehouse($request->user(), (int) $data['warehouse_id']);
        if (WarehouseAccess::isScoped($request->user())) {
            $approval = $this->approvals->request(
                $request->user(),
                'warehouse.counts.request',
                'inventory_count.create',
                $data,
                [(int) $data['warehouse_id']],
            );

            return $this->ok(['pending_approval' => true, 'approval_request' => $approval], 202);
        }

        $count = InventoryCount::query()->create([
            'count_number' => $this->inventory->nextNumber('CNT'),
            'warehouse_id' => $data['warehouse_id'],
            'count_date' => $data['count_date'],
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        foreach ($data['lines'] as $line) {
            $systemQuery = StockLevel::query()
                ->where('warehouse_id', $data['warehouse_id'])
                ->where('product_id', $line['product_id']);
            if (! empty($line['batch_no'])) {
                $systemQuery->where('batch_no', $line['batch_no']);
            }
            $system = (float) ($systemQuery->sum('quantity') ?? 0);
            $counted = (float) $line['counted_qty'];
            $count->lines()->create([
                'product_id' => $line['product_id'],
                'system_qty' => $system,
                'counted_qty' => $counted,
                'difference' => round($counted - $system, 3),
                'batch_no' => $line['batch_no'] ?? null,
                'serial_no' => $line['serial_no'] ?? null,
            ]);
        }

        return $this->ok($count->load(['warehouse', 'lines.product']), 201);
    }

    public function postCount(InventoryCount $inventoryCount): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.counts.request']);
        WarehouseAccess::assertWarehouse(request()->user(), (int) $inventoryCount->warehouse_id);
        if (WarehouseAccess::isScoped(request()->user())) {
            $approval = $this->approvals->request(
                request()->user(),
                'warehouse.counts.request',
                'inventory_count.post',
                array_merge(
                    $inventoryCount->load('lines')->toArray(),
                    ['requested_status' => 'posted'],
                ),
                [(int) $inventoryCount->warehouse_id],
                $inventoryCount,
            );

            return $this->ok(['pending_approval' => true, 'approval_request' => $approval], 202);
        }

        return $this->ok($this->inventory->postInventoryCount($inventoryCount, request()->user()));
    }
}
