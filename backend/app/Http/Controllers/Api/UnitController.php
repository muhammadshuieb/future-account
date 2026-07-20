<?php

namespace App\Http\Controllers\Api;

use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->ok(Unit::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:16'],
        ]);

        return $this->ok(Unit::query()->create($data), 201);
    }

    public function update(Request $request, Unit $unit): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $unit->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:16'],
        ]));

        return $this->ok($unit);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $unit->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
