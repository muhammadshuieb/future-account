<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesInvoice;
use App\Services\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesInvoiceController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok(SalesInvoice::query()->with(['customer', 'warehouse'])->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $request->validate([
            'invoice_date' => ['required', 'date'],
            'customer_id' => ['required', 'exists:customers,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->ok($this->sales->createInvoice($data, $data['lines'], $request->user()), 201);
    }

    public function show(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok($salesInvoice->load(['customer', 'warehouse', 'lines.product', 'journalEntry']));
    }

    public function post(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.manage');

        return $this->ok($this->sales->postInvoice($salesInvoice, request()->user()));
    }
}
