<?php

namespace App\Http\Controllers\Api;

use App\Models\Warehouse;
use App\Services\WarehouseApprovalService;
use App\Support\ListSearch;
use App\Support\WarehouseAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends ApiController
{
    public function __construct(protected WarehouseApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = Warehouse::query()->with('branch')->orderBy('code');
        WarehouseAccess::scopeWarehouseColumn($query, $request->user(), 'id');
        ListSearch::apply($query, $request, ['name', 'code', 'location', 'notes']);

        return $this->ok($query->get());
    }

    public function transferTargets(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.transfers.request']);

        return $this->ok(
            Warehouse::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'name', 'code'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:warehouses,code'],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        return $this->ok(Warehouse::query()->create($data)->load('branch'), 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        WarehouseAccess::assertWarehouse(request()->user(), $warehouse->id);

        return $this->ok($warehouse->load(['branch', 'stockLevels.product']));
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizeAnyPermission(['warehouse.manage', 'warehouse.master.request']);
        WarehouseAccess::assertWarehouse($request->user(), $warehouse->id);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:warehouses,code,'.$warehouse->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
        if (WarehouseAccess::isScoped($request->user())) {
            $approval = $this->approvals->request(
                $request->user(),
                'warehouse.master.request',
                'warehouse.update',
                $data,
                [$warehouse->id],
                $warehouse,
            );

            return $this->ok(['pending_approval' => true, 'approval_request' => $approval], 202);
        }
        $warehouse->update($data);

        return $this->ok($warehouse->fresh('branch'));
    }

    public function destroy(Warehouse $warehouse): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $warehouse->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
