<?php

namespace App\Http\Controllers\Api;

use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.view');
        $query = Warehouse::query()->with('branch')->orderBy('code');
        if ($request->filled('search')) {
            $s = $request->string('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }

        return $this->ok($query->get());
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

        return $this->ok($warehouse->load(['branch', 'stockLevels.product']));
    }

    public function update(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:warehouses,code,'.$warehouse->id],
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
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
