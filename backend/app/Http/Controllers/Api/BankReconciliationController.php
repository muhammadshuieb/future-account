<?php

namespace App\Http\Controllers\Api;

use App\Models\BankReconciliation;
use App\Services\CashService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankReconciliationController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.view');
        $query = BankReconciliation::query()->with('bank')->latest('id');
        ListSearch::apply($query, $request, ['notes', 'status'], [
            'bank' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $data = $request->validate([
            'bank_id' => ['required', 'exists:banks,id'],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:open,closed'],
        ]);

        return $this->ok($this->cash->createReconciliation($data, $request->user())->load('bank'), 201);
    }
}
