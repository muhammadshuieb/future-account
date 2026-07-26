<?php

namespace App\Http\Controllers\Api;

use App\Models\CurrencyExchange;
use App\Services\CashService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyExchangeController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.view');
        $query = CurrencyExchange::query()
            ->with(['sourceCashBox', 'targetCashBox', 'journalEntry', 'creator'])
            ->withCount('attachments')
            ->latest('id');
        ListSearch::apply($query, $request, ['exchange_number', 'notes', 'source_currency', 'target_currency', 'status', 'source_amount', 'target_amount'], [
            'sourceCashBox' => ['name', 'code'],
            'targetCashBox' => ['name', 'code'],
        ]);

        return $this->ok($query->get());
    }

    public function show(CurrencyExchange $currencyExchange): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(
            $currencyExchange->load(['sourceCashBox', 'targetCashBox', 'journalEntry.details.account', 'creator', 'attachments'])
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.manage');

        // Accept from_currency / to_currency aliases from the UI.
        if (! $request->filled('source_currency') && $request->filled('from_currency')) {
            $request->merge(['source_currency' => $request->input('from_currency')]);
        }
        if (! $request->filled('target_currency') && $request->filled('to_currency')) {
            $request->merge(['target_currency' => $request->input('to_currency')]);
        }

        $data = $request->validate([
            'exchange_date' => ['required', 'date'],
            'source_cash_box_id' => ['required', 'integer', 'exists:cash_boxes,id'],
            'target_cash_box_id' => ['required', 'integer', 'exists:cash_boxes,id', 'different:source_cash_box_id'],
            'source_currency' => ['required', 'string', 'max:8'],
            'target_currency' => ['required', 'string', 'max:8', 'different:source_currency'],
            'from_currency' => ['nullable', 'string', 'max:8'],
            'to_currency' => ['nullable', 'string', 'max:8'],
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
