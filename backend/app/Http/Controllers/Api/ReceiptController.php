<?php

namespace App\Http\Controllers\Api;

use App\Models\Receipt;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok(Receipt::query()->with(['customer', 'invoice'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $request->validate([
            'receipt_date' => ['required', 'date'],
            'customer_id' => ['required', 'exists:customers,id'],
            'sales_invoice_id' => ['nullable', 'exists:sales_invoices,id'],
            'cash_box_id' => ['nullable', 'exists:cash_boxes,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'method' => ['required', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
        ]);

        return $this->ok($this->sales->createReceipt($data, $request->user()), 201);
    }

    public function post(Receipt $receipt): JsonResponse
    {
        $this->authorizePermission('sales.manage');

        return $this->ok($this->sales->postReceipt($receipt, request()->user()));
    }

    public function destroy(Receipt $receipt): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $this->sales->deleteReceipt($receipt);

        return response()->json(['message' => 'تم حذف سند القبض.']);
    }
}
