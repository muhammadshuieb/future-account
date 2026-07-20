<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesReturn;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok(SalesReturn::query()->with(['customer', 'warehouse'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $request->validate([
            'return_date' => ['required', 'date'],
            'customer_id' => ['required', 'exists:customers,id'],
            'sales_invoice_id' => ['nullable', 'exists:sales_invoices,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->ok($this->sales->createReturn($data, $data['lines'], $request->user()), 201);
    }

    public function post(SalesReturn $salesReturn): JsonResponse
    {
        $this->authorizePermission('sales.manage');

        return $this->ok($this->sales->postReturn($salesReturn, request()->user()));
    }
}
