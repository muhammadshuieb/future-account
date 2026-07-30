<?php

namespace App\Http\Controllers\Api;

use App\Models\SalesInvoice;
use App\Services\SalesService;
use App\Support\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesInvoiceController extends ApiController
{
    public function __construct(protected SalesService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.view');
        $query = SalesInvoice::query()->with(['customer', 'warehouse', 'branch'])->withCount('attachments')->latest('id');
        ListSearch::apply($query, $request, ['invoice_number', 'notes', 'currency', 'total', 'status'], [
            'customer' => ['name', 'code'],
            'branch' => ['name', 'code'],
        ]);
        ListSearch::applyUnsettledInvoiceFilter($query, $request);

        return $this->ok($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $request->validate([
            'invoice_date' => ['required', 'date'],
            'customer_id' => ['required', 'exists:customers,id'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'cash_box_id' => ['nullable', 'exists:cash_boxes,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'payment_type' => ['nullable', 'in:cash,credit,partial'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,posted'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:64'],
            'lines.*.serial_no' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->ok($this->sales->createInvoice($data, $data['lines'], $request->user()), 201);
    }

    public function show(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.view');

        return $this->ok($salesInvoice->load(['customer', 'warehouse', 'branch', 'cashBox', 'lines.product.unit', 'journalEntry', 'attachments']));
    }

    public function post(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.manage');

        return $this->ok($this->sales->postInvoice($salesInvoice, request()->user()));
    }

    public function collect(Request $request, SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $data = $request->validate([
            'receipt_date' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'cash_box_id' => ['nullable', 'exists:cash_boxes,id'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'method' => ['nullable', 'in:cash,bank'],
            'currency' => ['nullable', 'string', 'max:8'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'base_amount' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $receipt = $this->sales->collectRemaining($salesInvoice, $request->user(), $data);

        return $this->ok([
            'receipt' => $receipt,
            'invoice' => $salesInvoice->fresh(['customer', 'cashBox']),
        ], 201);
    }

    public function destroy(SalesInvoice $salesInvoice): JsonResponse
    {
        $this->authorizePermission('sales.manage');
        $this->sales->deleteInvoice($salesInvoice);

        return response()->json(['message' => 'تم حذف فاتورة المبيعات.']);
    }
}
