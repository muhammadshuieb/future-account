<?php

namespace App\Http\Controllers\Api;

use App\Models\CashTransfer;
use App\Services\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashTransferController extends ApiController
{
    public function __construct(protected CashService $cash) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('cash.view');

        return $this->ok(CashTransfer::query()->latest('id')->get());
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
