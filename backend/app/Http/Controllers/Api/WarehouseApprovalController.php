<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Models\WarehouseApprovalRequest;
use App\Services\WarehouseApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseApprovalController extends ApiController
{
    public function __construct(protected WarehouseApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.approvals.review', 'warehouse.approvals.view_own']);
        $query = WarehouseApprovalRequest::query()
            ->with(['requester:id,name', 'reviewer:id,name', 'warehouse:id,name'])
            ->latest('id');

        if (! $request->user()->hasRole('admin') && ! $request->user()->can('warehouse.approvals.review')) {
            $query->where('requester_id', $request->user()->id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $items = $query->limit(250)->get();
        if ($request->user()->hasRole('admin') || $request->user()->can('warehouse.approvals.review')) {
            $items->each(fn (WarehouseApprovalRequest $item) => $this->appendReviewContext($item));
        }

        return $this->ok([
            'items' => $items,
            'pending_count' => (clone $query)->where('status', 'pending')->count(),
        ]);
    }

    public function show(Request $request, WarehouseApprovalRequest $warehouseApprovalRequest): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.approvals.review', 'warehouse.approvals.view_own']);
        if (! $request->user()->hasRole('admin')
            && ! $request->user()->can('warehouse.approvals.review')
            && $warehouseApprovalRequest->requester_id !== $request->user()->id) {
            abort(403, 'ليس لديك صلاحية عرض هذا الطلب.');
        }

        $warehouseApprovalRequest->load(['requester:id,name', 'reviewer:id,name', 'warehouse:id,name']);
        if ($request->user()->hasRole('admin') || $request->user()->can('warehouse.approvals.review')) {
            $this->appendReviewContext($warehouseApprovalRequest);
        }

        return $this->ok($warehouseApprovalRequest);
    }

    public function approve(Request $request, WarehouseApprovalRequest $warehouseApprovalRequest): JsonResponse
    {
        $this->authorizePermission('warehouse.approvals.review');
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return $this->ok($this->approvals->approve($warehouseApprovalRequest, $request->user(), $data['comment'] ?? null));
    }

    public function reject(Request $request, WarehouseApprovalRequest $warehouseApprovalRequest): JsonResponse
    {
        $this->authorizePermission('warehouse.approvals.review');
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        return $this->ok($this->approvals->reject($warehouseApprovalRequest, $request->user(), $data['comment']));
    }

    protected function appendReviewContext(WarehouseApprovalRequest $approvalRequest): void
    {
        if ($approvalRequest->action_type !== 'warehouse_transfer.create') {
            return;
        }

        $payload = $approvalRequest->after_payload;
        $sourceId = (int) ($payload['from_warehouse_id'] ?? 0);
        $targetId = (int) ($payload['to_warehouse_id'] ?? 0);
        $warehouses = Warehouse::query()
            ->whereKey([$sourceId, $targetId])
            ->get(['id', 'name', 'code'])
            ->keyBy('id');
        $productIds = collect($payload['lines'] ?? [])
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $products = Product::query()
            ->whereIn('id', $productIds->all())
            ->get(['id', 'sku', 'name', 'track_batch', 'track_serial'])
            ->keyBy('id');

        $lines = collect($payload['lines'] ?? [])->map(function (array $line) use ($sourceId, $targetId, $products) {
            $productId = (int) $line['product_id'];
            $batchNo = (string) ($line['batch_no'] ?? '');
            $sourceStock = StockLevel::query()
                ->where('warehouse_id', $sourceId)
                ->where('product_id', $productId)
                ->when($batchNo !== '', fn ($query) => $query->where('batch_no', $batchNo))
                ->sum('quantity');
            $targetStock = StockLevel::query()
                ->where('warehouse_id', $targetId)
                ->where('product_id', $productId)
                ->when($batchNo !== '', fn ($query) => $query->where('batch_no', $batchNo))
                ->sum('quantity');

            return [
                'product' => $products->get($productId),
                'quantity' => (float) $line['quantity'],
                'batch_no' => $line['batch_no'] ?? null,
                'serial_no' => $line['serial_no'] ?? null,
                'source_current_stock' => round((float) $sourceStock, 3),
                'target_current_stock' => round((float) $targetStock, 3),
            ];
        })->values();

        $approvalRequest->setAttribute('review_context', [
            'source_warehouse' => $warehouses->get($sourceId),
            'target_warehouse' => $warehouses->get($targetId),
            'lines' => $lines,
        ]);
    }
}
