<?php

namespace App\Http\Controllers\Api;

use App\Models\ProfitDistribution;
use App\Services\ProfitDistributionService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfitDistributionController extends ApiController
{
    public function __construct(protected ProfitDistributionService $distributions) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('profit_distributions.view');
        $query = ProfitDistribution::query()
            ->with(['cashBox', 'lines.partner', 'creator'])
            ->latest('id');
        ListSearch::apply($query, $request, ['distribution_number', 'notes', 'status', 'currency', 'total_amount'], [
            'cashBox' => ['name', 'code'],
            'lines.partner' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function show(ProfitDistribution $profitDistribution): JsonResponse
    {
        $this->authorizePermission('profit_distributions.view');

        return $this->ok($profitDistribution->load(['cashBox', 'lines.partner.account', 'journalEntry', 'creator']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('profit_distributions.manage');
        $data = $request->validate([
            'distribution_date' => ['required', 'date'],
            'cash_box_id' => ['required', 'integer', 'exists:cash_boxes,id'],
            'total_amount' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
            'auto_split' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array'],
            'lines.*.equity_partner_id' => ['required_with:lines', 'integer', 'exists:equity_partners,id'],
            'lines.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return $this->ok($this->distributions->create($data, $request->user()), 201);
    }

    public function post(ProfitDistribution $profitDistribution): JsonResponse
    {
        $this->authorizePermission('profit_distributions.manage');

        return $this->ok($this->distributions->post($profitDistribution, request()->user()));
    }

    public function destroy(ProfitDistribution $profitDistribution): JsonResponse
    {
        $this->authorizePermission('profit_distributions.manage');
        $this->distributions->delete($profitDistribution);

        return response()->json(['message' => 'تم الحذف.']);
    }
}
