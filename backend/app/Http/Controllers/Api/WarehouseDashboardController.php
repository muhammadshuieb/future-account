<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Models\WarehouseApprovalRequest;
use App\Models\WarehouseTransfer;
use App\Services\InventoryService;
use App\Support\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseDashboardController extends ApiController
{
    public function __construct(protected InventoryService $inventory) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! WarehouseAccess::isScoped($user) || ! $user->can('warehouse.view')) {
            abort(403, 'هذه اللوحة مخصصة لمديري المخازن.');
        }

        $warehouseIds = WarehouseAccess::warehouseIds($user);
        $requestedWarehouseId = $request->filled('warehouse_id')
            ? (int) $request->query('warehouse_id')
            : null;

        if ($requestedWarehouseId !== null) {
            WarehouseAccess::assertWarehouse($user, $requestedWarehouseId);
            $selectedWarehouseIds = [$requestedWarehouseId];
        } elseif (count($warehouseIds) === 1) {
            $requestedWarehouseId = $warehouseIds[0];
            $selectedWarehouseIds = $warehouseIds;
        } else {
            $selectedWarehouseIds = $warehouseIds;
        }

        $warehouses = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->orderBy('code')
            ->get(['id', 'name', 'code']);

        $stock = StockLevel::query()
            ->join('products', 'products.id', '=', 'stock_levels.product_id')
            ->whereIn('stock_levels.warehouse_id', $selectedWarehouseIds)
            ->selectRaw('COUNT(DISTINCT stock_levels.product_id) as product_count')
            ->selectRaw('COALESCE(SUM(stock_levels.quantity), 0) as stock_quantity')
            ->selectRaw('COALESCE(SUM(stock_levels.quantity * products.cost_price), 0) as stock_value')
            ->first();

        $lowStockAlerts = [];
        foreach ($selectedWarehouseIds as $warehouseId) {
            $alerts = $this->inventory->lowStockAlerts($warehouseId);
            $stockedProductIds = StockLevel::query()
                ->where('warehouse_id', $warehouseId)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            array_push($lowStockAlerts, ...array_filter(
                $alerts,
                fn (array $alert) => in_array((int) $alert['product_id'], $stockedProductIds, true),
            ));
        }

        $requests = WarehouseApprovalRequest::query()
            ->with('warehouse:id,name,code')
            ->where('requester_id', $user->id)
            ->whereIn('warehouse_id', $selectedWarehouseIds)
            ->latest('id');

        $pendingRequests = (clone $requests)->where('status', 'pending')->count();
        $pendingTransferRequests = (clone $requests)
            ->where('status', 'pending')
            ->whereIn('action_type', ['warehouse_transfer.create', 'warehouse_transfer.post'])
            ->count();

        $pendingTransfers = WarehouseTransfer::query()
            ->with(['fromWarehouse:id,name,code', 'toWarehouse:id,name,code'])
            ->where('created_by', $user->id)
            ->where('status', '!=', 'posted')
            ->whereIn('from_warehouse_id', $warehouseIds)
            ->whereIn('to_warehouse_id', $warehouseIds)
            ->where(function ($query) use ($selectedWarehouseIds) {
                $query->whereIn('from_warehouse_id', $selectedWarehouseIds)
                    ->orWhereIn('to_warehouse_id', $selectedWarehouseIds);
            })
            ->latest('id')
            ->limit(8)
            ->get();

        return $this->ok([
            'warehouses' => $warehouses,
            'selected_warehouse_id' => $requestedWarehouseId,
            'currency' => strtoupper((string) Setting::getValue('currency', 'USD')),
            'product_count' => (int) ($stock?->product_count ?? 0),
            'stock_quantity' => round((float) ($stock?->stock_quantity ?? 0), 3),
            'stock_value' => round((float) ($stock?->stock_value ?? 0), 2),
            'low_stock_count' => count($lowStockAlerts),
            'low_stock_alerts' => array_slice(array_values($lowStockAlerts), 0, 8),
            'pending_request_count' => $pendingRequests,
            'pending_transfer_request_count' => $pendingTransferRequests,
            'recent_requests' => (clone $requests)->limit(8)->get(),
            'pending_transfers' => $pendingTransfers,
        ]);
    }
}
