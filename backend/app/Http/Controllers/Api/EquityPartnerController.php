<?php

namespace App\Http\Controllers\Api;

use App\Models\EquityPartner;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquityPartnerController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('profit_distributions.view');
        $query = EquityPartner::query()->with('account')->orderBy('code');
        ListSearch::apply($query, $request, ['code', 'name', 'phone', 'notes']);

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('profit_distributions.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:equity_partners,code'],
            'name' => ['required', 'string', 'max:255'],
            'share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $data['share_percent'] = round((float) ($data['share_percent'] ?? 0), 4);
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->ok(EquityPartner::query()->create($data)->load('account'), 201);
    }

    public function update(Request $request, EquityPartner $equityPartner): JsonResponse
    {
        $this->authorizePermission('profit_distributions.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('equity_partners', 'code')->ignore($equityPartner->id)],
            'name' => ['required', 'string', 'max:255'],
            'share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        if (array_key_exists('share_percent', $data)) {
            $data['share_percent'] = round((float) ($data['share_percent'] ?? 0), 4);
        }

        $equityPartner->update($data);

        return $this->ok($equityPartner->fresh('account'));
    }

    public function destroy(EquityPartner $equityPartner): JsonResponse
    {
        $this->authorizePermission('profit_distributions.manage');

        if ($equityPartner->distributionLines()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف شريك مرتبط بتوزيعات أرباح. عطّله بدلاً من ذلك.'], 422);
        }

        $equityPartner->delete();

        return response()->json(['message' => 'تم الحذف.']);
    }
}
