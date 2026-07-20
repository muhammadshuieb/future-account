<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizePermission('warehouse.view');

        return $this->ok(Category::query()->with('parent')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:categories,id'],
        ]);

        return $this->ok(Category::query()->create($data), 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $category->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:categories,id'],
        ]));

        return $this->ok($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorizePermission('warehouse.manage');
        $category->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
