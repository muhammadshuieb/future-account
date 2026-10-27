<?php

namespace App\Http\Controllers\Api;

use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PriceListController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAnyPermission(['price_lists.view', 'price_lists.manage']);

        $lists = PriceList::query()
            ->withCount('items')
            ->with('updatedBy:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return $this->ok($lists);
    }

    public function show(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizeAnyPermission(['price_lists.view', 'price_lists.manage']);

        $q = $request->string('q')->trim()->toString();
        $category = $request->string('category')->trim()->toString();

        $itemsQuery = $priceList->items()->getQuery();

        if ($category !== '') {
            $itemsQuery->where('category', $category);
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $itemsQuery->where(function ($w) use ($like) {
                $w->where('model_no', 'like', $like)
                    ->orWhere('specs', 'like', $like)
                    ->orWhere('mp', 'like', $like)
                    ->orWhere('lens', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('row_no', 'like', $like);
            });
        }

        $items = $itemsQuery->orderBy('sort_order')->orderBy('id')->get();

        // Prefer category header order (sheet order), then any leftover category names.
        $headerCategories = PriceListItem::query()
            ->where('price_list_id', $priceList->id)
            ->where('is_category_header', true)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->orderBy('sort_order')
            ->pluck('category')
            ->unique()
            ->values();

        $otherCategories = PriceListItem::query()
            ->where('price_list_id', $priceList->id)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->reject(fn ($c) => $headerCategories->contains($c))
            ->values();

        $categories = $headerCategories->concat($otherCategories)->values()->all();

        $priceList->load('updatedBy:id,name');

        return $this->ok([
            'list' => $priceList,
            'items' => $items,
            'categories' => $categories,
            'can_manage' => $this->userCan('price_lists.manage'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $list = PriceList::query()->create([
            'name' => $data['name'],
            'currency' => $data['currency'] ?? 'USD',
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'updated_by' => $request->user()?->id,
        ]);

        return $this->ok($list->load('updatedBy:id,name'), 201);
    }

    public function update(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $priceList->fill($data);
        $priceList->updated_by = $request->user()?->id;
        $priceList->save();

        return $this->ok($priceList->fresh()->load('updatedBy:id,name'));
    }

    public function destroy(PriceList $priceList): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');
        $priceList->delete();

        return response()->json(['message' => 'تم حذف قائمة الأسعار.']);
    }

    public function storeItem(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');
        $data = $this->validatedItem($request);

        $maxSort = (int) $priceList->items()->max('sort_order');
        $item = $priceList->items()->create(array_merge($data, [
            'sort_order' => $data['sort_order'] ?? ($maxSort + 1),
        ]));

        $priceList->forceFill(['updated_by' => $request->user()?->id])->save();

        return $this->ok($item, 201);
    }

    public function updateItem(Request $request, PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');
        if ((int) $item->price_list_id !== (int) $priceList->id) {
            abort(404);
        }

        $data = $this->validatedItem($request, partial: true);
        $item->fill($data)->save();
        $priceList->forceFill(['updated_by' => $request->user()?->id])->save();

        return $this->ok($item->fresh());
    }

    public function destroyItem(PriceList $priceList, PriceListItem $item): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');
        if ((int) $item->price_list_id !== (int) $priceList->id) {
            abort(404);
        }

        $item->delete();
        $priceList->forceFill(['updated_by' => auth()->id()])->save();

        return response()->json(['message' => 'تم حذف البند.']);
    }

    public function reorderItems(Request $request, PriceList $priceList): JsonResponse
    {
        $this->authorizePermission('price_lists.manage');

        $data = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', Rule::exists('price_list_items', 'id')->where('price_list_id', $priceList->id)],
        ]);

        DB::transaction(function () use ($data, $priceList, $request) {
            foreach ($data['ordered_ids'] as $index => $id) {
                PriceListItem::query()
                    ->where('price_list_id', $priceList->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $index + 1]);
            }
            $priceList->forceFill(['updated_by' => $request->user()?->id])->save();
        });

        return $this->ok(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedItem(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'nullable';

        return $request->validate([
            'sort_order' => [$req, 'integer', 'min:0'],
            'category' => [$req, 'nullable', 'string', 'max:255'],
            'row_no' => [$req, 'nullable', 'string', 'max:32'],
            'model_no' => [$req, 'nullable', 'string', 'max:255'],
            'mp' => [$req, 'nullable', 'string', 'max:64'],
            'specs' => [$req, 'nullable', 'string', 'max:5000'],
            'lens' => [$req, 'nullable', 'string', 'max:128'],
            'price_super_wholesale' => [$req, 'nullable', 'numeric'],
            'price_retail' => [$req, 'nullable', 'numeric'],
            'price_wholesale' => [$req, 'nullable', 'numeric'],
            'is_category_header' => [$req, 'boolean'],
            'is_active' => [$req, 'boolean'],
        ]);
    }
}
