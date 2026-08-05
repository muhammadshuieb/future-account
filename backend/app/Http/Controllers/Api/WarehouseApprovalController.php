<?php

namespace App\Http\Controllers\Api;

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

        return $this->ok([
            'items' => $query->limit(250)->get(),
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

        return $this->ok($warehouseApprovalRequest->load(['requester:id,name', 'reviewer:id,name', 'warehouse:id,name']));
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
}
