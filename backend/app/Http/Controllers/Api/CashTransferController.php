<?php

namespace App\Http\Controllers\Api;

use App\Models\CashTransfer;
use App\Services\CashService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashTransferController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.view');
        $query = CashTransfer::query()->latest('id');
        ListSearch::apply($query, $request, ['transfer_number', 'notes', 'amount', 'status', 'from_type', 'to_type']);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('cash.manage');
        $data = $request->validate([
            'transfer_date' => ['required', 'date'],
            'from_type' => ['required', 'in:cash_box,bank'],
            'from_id' => ['required', 'integer'],
            'to_type' => ['required', 'in:cash_box,bank'],
            'to_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        return $this->ok($this->cash->createTransfer($data, $request->user()), 201);
    }

    public function post(CashTransfer $cashTransfer): JsonResponse
    {
        $this->authorizePermission('cash.manage');

        return $this->ok($this->cash->postTransfer($cashTransfer, request()->user()));
    }
}
