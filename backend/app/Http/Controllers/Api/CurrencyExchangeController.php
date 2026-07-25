<?php

namespace App\Http\Controllers\Api;

use App\Models\CurrencyExchange;
use App\Services\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyExchangeController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(
            CurrencyExchange::query()
                ->with(['sourceCashBox', 'targetCashBox', 'journalEntry', 'creator'])
                ->latest('id')
                ->get()
        );
    }

    public function show(CurrencyExchange $currencyExchange): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(
            $currencyExchange->load(['sourceCashBox', 'targetCashBox', 'journalEntry.details.account', 'creator'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $data = $request->validate([
            'exchange_date' => ['required', 'date'],
            'source_cash_box_id' => ['required', 'integer', 'exists:cash_boxes,id'],
            'target_cash_box_id' => ['required', 'integer', 'exists:cash_boxes,id', 'different:source_cash_box_id'],
            'source_currency' => ['nullable', 'string', 'max:8'],
            'target_currency' => ['nullable', 'string', 'max:8'],
            'source_amount' => ['required', 'numeric', 'gt:0'],
            'target_amount' => ['required', 'numeric', 'gt:0'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        return $this->ok($this->cash->createExchange($data, $request->user()), 201);
    }

    public function post(CurrencyExchange $currencyExchange): JsonResponse
    {
        $this->authorizePermission('cash.manage');

        return $this->ok($this->cash->postExchange($currencyExchange, request()->user()));
    }
}
