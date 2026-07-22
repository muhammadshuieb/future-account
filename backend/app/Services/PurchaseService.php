<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected InventoryService $inventory,
        protected AuditLogger $audit,
        protected CurrencyService $currencies,
    ) {}

    public function createInvoice(array $data, array $lines, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines);

            $fx = $this->currencies->resolveDocumentFx(
                $total,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['invoice_date'] ?? null,
            );

            $invoice = PurchaseInvoice::query()->create([
                'invoice_number' => $this->nextNumber('PI'),
                'invoice_date' => $data['invoice_date'],
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $this->resolveWarehouseId(isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null),
                'branch_id' => $data['branch_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'status' => 'draft',
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                $invoice->lines()->create($line);
            }

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postInvoice($invoice, $user);
            }

            return $invoice->load(['lines.product', 'supplier', 'warehouse']);
        });
    }

    public function postInvoice(PurchaseInvoice $invoice, User $user): PurchaseInvoice
    {
        if ($invoice->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['فاتورة المشتريات مرحّلة مسبقاً.']]);
        }

        return DB::transaction(function () use ($invoice, $user) {
            $invoice->load(['lines.product', 'supplier']);

            if (! $invoice->warehouse_id) {
                throw ValidationException::withMessages(['warehouse_id' => ['يجب تحديد المخزن قبل الترحيل.']]);
            }

            $ap = $invoice->supplier->account_id
                ? Account::query()->findOrFail($invoice->supplier->account_id)
                : Account::query()->where('code', '2101')->firstOrFail();
            $inventory = Account::query()->where('code', '1104')->firstOrFail();
            $vat = Account::query()->where('code', '2102')->firstOrFail();

            $rate = (float) ($invoice->exchange_rate ?: 1);
            $baseSubtotal = round((float) $invoice->subtotal * $rate, 2);
            $baseTax = round((float) $invoice->tax_amount * $rate, 2);
            $baseTotal = (float) ($invoice->base_amount ?: round((float) $invoice->total * $rate, 2));

            $glLines = [
                ['account_id' => $inventory->id, 'debit' => $baseSubtotal, 'credit' => 0],
            ];

            if ($baseTax > 0) {
                // Input VAT simplified as debit to VAT (offset against payable in full ERP)
                $glLines[] = ['account_id' => $vat->id, 'debit' => $baseTax, 'credit' => 0];
            }

            $glLines[] = ['account_id' => $ap->id, 'debit' => 0, 'credit' => $baseTotal];

            $entry = $this->journals->create([
                'entry_date' => $invoice->invoice_date->toDateString(),
                'description' => 'ترحيل فاتورة مشتريات '.$invoice->invoice_number,
                'reference' => $invoice->invoice_number,
                'status' => 'posted',
            ], $glLines, $user);

            foreach ($invoice->lines as $line) {
                $product = $line->product;
                $product->update(['cost_price' => $line->unit_cost]);

                $this->inventory->adjustStock(
                    $invoice->warehouse_id,
                    $line->product_id,
                    (float) $line->quantity,
                    'in',
                    $user,
                    [
                        'movement_date' => $invoice->invoice_date->toDateString(),
                        'unit_cost' => $line->unit_cost,
                        'batch_no' => $product->track_batch ? ($line->batch_no ?? null) : null,
                        'serial_no' => $line->serial_no,
                        'reference_type' => $invoice::class,
                        'reference_id' => $invoice->id,
                        'notes' => 'استلام مشتريات '.$invoice->invoice_number,
                        'journal_entry_id' => $entry->id,
                    ]
                );
            }

            $invoice->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ]);

            $this->audit->log($user, 'purchase_invoice.posted', $invoice);

            return $invoice->fresh(['lines.product', 'supplier', 'warehouse', 'journalEntry']);
        });
    }

    public function createReturn(array $data, array $lines, User $user): PurchaseReturn
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            $total = 0;
            $normalized = [];
            foreach ($lines as $line) {
                $lt = round((float) $line['quantity'] * (float) $line['unit_cost'], 2);
                $total += $lt;
                $normalized[] = [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'line_total' => $lt,
                    'batch_no' => $line['batch_no'] ?? null,
                    'serial_no' => $line['serial_no'] ?? null,
                ];
            }

            $ret = PurchaseReturn::query()->create([
                'return_number' => $this->nextNumber('PR'),
                'return_date' => $data['return_date'],
                'supplier_id' => $data['supplier_id'],
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => 'draft',
                'total' => $total,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                $ret->lines()->create($line);
            }

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postReturn($ret, $user);
            }

            return $ret->load(['lines.product', 'supplier']);
        });
    }

    public function postReturn(PurchaseReturn $ret, User $user): PurchaseReturn
    {
        if ($ret->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['مرتجع المشتريات مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($ret, $user) {
            $ret->load(['lines', 'supplier']);
            $ap = $ret->supplier->account_id
                ? Account::query()->findOrFail($ret->supplier->account_id)
                : Account::query()->where('code', '2101')->firstOrFail();
            $inventory = Account::query()->where('code', '1104')->firstOrFail();

            $entry = $this->journals->create([
                'entry_date' => $ret->return_date->toDateString(),
                'description' => 'مرتجع مشتريات '.$ret->return_number,
                'reference' => $ret->return_number,
                'status' => 'posted',
            ], [
                ['account_id' => $ap->id, 'debit' => (float) $ret->total, 'credit' => 0],
                ['account_id' => $inventory->id, 'debit' => 0, 'credit' => (float) $ret->total],
            ], $user);

            if ($ret->warehouse_id) {
                foreach ($ret->lines as $line) {
                    $this->inventory->adjustStock(
                        $ret->warehouse_id,
                        $line->product_id,
                        -((float) $line->quantity),
                        'out',
                        $user,
                        [
                            'movement_date' => $ret->return_date->toDateString(),
                            'reference_type' => $ret::class,
                            'reference_id' => $ret->id,
                            'notes' => 'مرتجع مشتريات '.$ret->return_number,
                        ]
                    );
                }
            }

            $ret->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $ret->fresh(['lines.product', 'supplier']);
        });
    }

    public function createPayment(array $data, User $user): SupplierPayment
    {
        return DB::transaction(function () use ($data, $user) {
            $payment = SupplierPayment::query()->create([
                'payment_number' => $this->nextNumber('SP'),
                'payment_date' => $data['payment_date'],
                'supplier_id' => $data['supplier_id'],
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'cash_box_id' => $data['cash_box_id'] ?? null,
                'bank_id' => $data['bank_id'] ?? null,
                'method' => $data['method'] ?? 'cash',
                'amount' => $data['amount'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postPayment($payment, $user);
            }

            return $payment->load(['supplier', 'invoice']);
        });
    }

    public function postPayment(SupplierPayment $payment, User $user): SupplierPayment
    {
        if ($payment->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['سند الصرف مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($payment, $user) {
            $payment->load('supplier');
            $cashCode = $payment->method === 'bank' ? '1102' : '1101';

            if ($payment->method === 'bank' && $payment->bank_id) {
                $bank = \App\Models\Bank::query()->findOrFail($payment->bank_id);
                $creditAccount = $bank->account_id
                    ? Account::query()->findOrFail($bank->account_id)
                    : Account::query()->where('code', '1102')->firstOrFail();
            } elseif ($payment->cash_box_id) {
                $box = \App\Models\CashBox::query()->findOrFail($payment->cash_box_id);
                $creditAccount = $box->account_id
                    ? Account::query()->findOrFail($box->account_id)
                    : Account::query()->where('code', $cashCode)->firstOrFail();
            } else {
                $creditAccount = Account::query()->where('code', $cashCode)->firstOrFail();
            }

            $ap = $payment->supplier->account_id
                ? Account::query()->findOrFail($payment->supplier->account_id)
                : Account::query()->where('code', '2101')->firstOrFail();

            $entry = $this->journals->create([
                'entry_date' => $payment->payment_date->toDateString(),
                'description' => 'سند صرف مورد '.$payment->payment_number,
                'reference' => $payment->payment_number,
                'status' => 'posted',
            ], [
                ['account_id' => $ap->id, 'debit' => (float) $payment->amount, 'credit' => 0],
                ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => (float) $payment->amount],
            ], $user);

            if ($payment->purchase_invoice_id) {
                PurchaseInvoice::query()->findOrFail($payment->purchase_invoice_id)
                    ->increment('paid_amount', (float) $payment->amount);
            }

            $payment->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $payment->fresh(['supplier', 'invoice']);
        });
    }

    protected function normalizeLines(array $lines): array
    {
        $taxRateDefault = (float) (Setting::query()->where('key', 'tax_rate')->value('value') ?? 15);
        $subtotal = 0;
        $tax = 0;
        $normalized = [];

        foreach ($lines as $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            $this->inventory->validateBatchSerial($product, $line);
            $qty = (float) $line['quantity'];
            $cost = (float) ($line['unit_cost'] ?? $product->cost_price);
            $rate = (float) ($line['tax_rate'] ?? $taxRateDefault);
            $lineSub = round($qty * $cost, 2);
            $lineTax = round($lineSub * $rate / 100, 2);
            $subtotal += $lineSub;
            $tax += $lineTax;
            $normalized[] = [
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_cost' => $cost,
                'tax_rate' => $rate,
                'line_total' => round($lineSub + $lineTax, 2),
                'batch_no' => $line['batch_no'] ?? null,
                'serial_no' => $line['serial_no'] ?? null,
            ];
        }

        return [$subtotal, $tax, round($subtotal + $tax, 2), $normalized];
    }

    public function createRequest(array $data, array $lines, User $user): PurchaseRequest
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines);
            $fx = $this->currencies->resolveDocumentFx($total, $data['currency'] ?? null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null, $data['request_date'] ?? null);

            $request = PurchaseRequest::query()->create([
                'request_number' => $this->nextNumber('PREQ'),
                'request_date' => $data['request_date'],
                'required_date' => $data['required_date'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                $request->items()->create($line);
            }

            return $request->load(['items.product', 'supplier', 'warehouse']);
        });
    }

    public function updateRequest(PurchaseRequest $request, array $data, array $lines): PurchaseRequest
    {
        if (in_array($request->status, ['converted', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['لا يمكن تعديل طلب محوّل أو ملغى.']]);
        }

        return DB::transaction(function () use ($request, $data, $lines) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines);
            $request->update([
                'request_date' => $data['request_date'] ?? $request->request_date,
                'required_date' => $data['required_date'] ?? $request->required_date,
                'supplier_id' => $data['supplier_id'] ?? $request->supplier_id,
                'warehouse_id' => $data['warehouse_id'] ?? $request->warehouse_id,
                'notes' => $data['notes'] ?? $request->notes,
                'status' => $data['status'] ?? $request->status,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
            ]);
            $request->items()->delete();
            foreach ($normalized as $line) {
                $request->items()->create($line);
            }

            return $request->fresh(['items.product', 'supplier', 'warehouse']);
        });
    }

    public function convertRequestToOrder(PurchaseRequest $request, User $user, array $overrides = []): PurchaseOrder
    {
        if ($request->status === 'converted') {
            throw ValidationException::withMessages(['status' => ['طلب الشراء محوّل مسبقاً.']]);
        }

        if (! $request->supplier_id && empty($overrides['supplier_id'])) {
            throw ValidationException::withMessages(['supplier_id' => ['يجب تحديد المورد قبل التحويل لأمر شراء.']]);
        }

        return DB::transaction(function () use ($request, $user, $overrides) {
            $request->load('items');
            $order = PurchaseOrder::query()->create([
                'order_number' => $this->nextNumber('PO'),
                'order_date' => $overrides['order_date'] ?? now()->toDateString(),
                'supplier_id' => $overrides['supplier_id'] ?? $request->supplier_id,
                'purchase_request_id' => $request->id,
                'warehouse_id' => $overrides['warehouse_id'] ?? $request->warehouse_id,
                'branch_id' => $request->branch_id,
                'status' => 'confirmed',
                'currency' => $request->currency,
                'exchange_rate' => $request->exchange_rate,
                'base_amount' => $request->base_amount,
                'subtotal' => $request->subtotal,
                'tax_amount' => $request->tax_amount,
                'total' => $request->total,
                'notes' => $request->notes,
                'created_by' => $user->id,
            ]);

            foreach ($request->items as $item) {
                $order->items()->create($item->only([
                    'product_id', 'quantity', 'unit_cost', 'tax_rate', 'line_total', 'batch_no', 'serial_no',
                ]));
            }

            $request->update(['status' => 'converted']);

            return $order->load(['items.product', 'supplier', 'request']);
        });
    }

    public function createOrder(array $data, array $lines, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines);
            $fx = $this->currencies->resolveDocumentFx($total, $data['currency'] ?? null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null, $data['order_date'] ?? null);

            $order = PurchaseOrder::query()->create([
                'order_number' => $this->nextNumber('PO'),
                'order_date' => $data['order_date'],
                'supplier_id' => $data['supplier_id'],
                'purchase_request_id' => $data['purchase_request_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                $order->items()->create($line);
            }

            return $order->load(['items.product', 'supplier', 'warehouse']);
        });
    }

    public function convertOrderToInvoice(PurchaseOrder $order, User $user, array $overrides = []): PurchaseInvoice
    {
        if ($order->status === 'converted') {
            throw ValidationException::withMessages(['status' => ['أمر الشراء محوّل مسبقاً.']]);
        }

        $order->load('items');
        $lines = $order->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'unit_cost' => $item->unit_cost,
            'tax_rate' => $item->tax_rate,
            'batch_no' => $item->batch_no,
            'serial_no' => $item->serial_no,
        ])->all();

        $invoice = $this->createInvoice([
            'invoice_date' => $overrides['invoice_date'] ?? now()->toDateString(),
            'supplier_id' => $order->supplier_id,
            'warehouse_id' => $overrides['warehouse_id'] ?? $order->warehouse_id,
            'branch_id' => $order->branch_id,
            'purchase_order_id' => $order->id,
            'currency' => $order->currency,
            'exchange_rate' => $order->exchange_rate,
            'status' => $overrides['status'] ?? 'draft',
            'notes' => $order->notes,
        ], $lines, $user);

        $order->update(['status' => 'converted']);

        return $invoice;
    }

    public function nextNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $full = "{$prefix}-{$year}-";

        $last = match ($prefix) {
            'PI' => PurchaseInvoice::query()->where('invoice_number', 'like', $full.'%')->orderByDesc('invoice_number')->value('invoice_number'),
            'PR' => PurchaseReturn::query()->where('return_number', 'like', $full.'%')->orderByDesc('return_number')->value('return_number'),
            'SP' => SupplierPayment::query()->where('payment_number', 'like', $full.'%')->orderByDesc('payment_number')->value('payment_number'),
            'PREQ' => PurchaseRequest::query()->where('request_number', 'like', $full.'%')->orderByDesc('request_number')->value('request_number'),
            'PO' => PurchaseOrder::query()->where('order_number', 'like', $full.'%')->orderByDesc('order_number')->value('order_number'),
            default => null,
        };

        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    protected function resolveWarehouseId(?int $warehouseId): ?int
    {
        if ($warehouseId) {
            return $warehouseId;
        }

        $default = Setting::getValue('default_warehouse_id');

        return $default ? (int) $default : null;
    }

    public function deleteRequest(PurchaseRequest $request): void
    {
        if ($request->status === 'converted') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف طلب محوّل.'],
            ]);
        }

        DB::transaction(function () use ($request) {
            $request->items()->delete();
            $request->delete();
        });
    }

    public function deleteOrder(PurchaseOrder $order): void
    {
        if ($order->status === 'converted') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف أمر محوّل.'],
            ]);
        }

        DB::transaction(function () use ($order) {
            $order->items()->delete();
            $order->delete();
        });
    }

    public function deleteInvoice(PurchaseInvoice $invoice): void
    {
        $this->assertDraftNotPosted($invoice->status);

        DB::transaction(function () use ($invoice) {
            $invoice->lines()->delete();
            $invoice->delete();
        });
    }

    public function deleteReturn(PurchaseReturn $ret): void
    {
        $this->assertDraftNotPosted($ret->status);

        DB::transaction(function () use ($ret) {
            $ret->lines()->delete();
            $ret->delete();
        });
    }

    public function deletePayment(SupplierPayment $payment): void
    {
        $this->assertDraftNotPosted($payment->status);

        if ($payment->journal_entry_id) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف سند مرتبط بقيد محاسبي.'],
            ]);
        }

        $payment->delete();
    }

    protected function assertDraftNotPosted(string $status): void
    {
        if ($status === 'posted') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف مستند مرحّل. يمكنك إلغاؤه إن وُجدت خاصية void'],
            ]);
        }

        if ($status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['يمكن حذف المسودات فقط.'],
            ]);
        }
    }

    public function supplierStatement(Supplier $supplier): array
    {
        $invoices = $supplier->invoices()->where('status', 'posted')->orderBy('invoice_date')->get();
        $payments = $supplier->payments()->where('status', 'posted')->orderBy('payment_date')->get();
        $rows = [];
        $balance = 0;

        foreach ($invoices as $inv) {
            $balance += (float) $inv->total;
            $rows[] = [
                'date' => $inv->invoice_date->toDateString(),
                'type' => 'invoice',
                'number' => $inv->invoice_number,
                'debit' => 0,
                'credit' => (float) $inv->total,
                'balance' => $balance,
            ];
        }

        foreach ($payments as $pay) {
            $balance -= (float) $pay->amount;
            $rows[] = [
                'date' => $pay->payment_date->toDateString(),
                'type' => 'payment',
                'number' => $pay->payment_number,
                'debit' => (float) $pay->amount,
                'credit' => 0,
                'balance' => $balance,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ['supplier' => $supplier, 'rows' => $rows, 'balance' => $balance];
    }
}
