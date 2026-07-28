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
    /** Same-day ordering so a statement reads invoice → return → payment. */
    protected const STATEMENT_ORDER = ['invoice' => 1, 'return' => 2, 'payment' => 3];

    public function __construct(
        protected JournalEntryService $journals,
        protected InventoryService $inventory,
        protected AuditLogger $audit,
        protected CurrencyService $currencies,
        protected CashService $cash,
    ) {}

    public function createInvoice(array $data, array $lines, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines);

            [$paymentType, $intendedPaid, $cashBoxId] = $this->normalizePaymentTerms($data, $total);

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
                'cash_box_id' => $cashBoxId,
                'branch_id' => $data['branch_id'] ?? null,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'status' => 'draft',
                'payment_type' => $paymentType,
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'paid_amount' => in_array($paymentType, ['cash', 'partial'], true) ? $intendedPaid : 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                $invoice->lines()->create($line);
            }

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postInvoice($invoice, $user, $intendedPaid);
            }

            return $invoice->load(['lines.product.unit', 'supplier', 'warehouse', 'cashBox', 'attachments']);
        });
    }

    public function postInvoice(PurchaseInvoice $invoice, User $user, ?float $intendedPaidOverride = null): PurchaseInvoice
    {
        if ($invoice->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['فاتورة المشتريات مرحّلة مسبقاً.']]);
        }

        return DB::transaction(function () use ($invoice, $user, $intendedPaidOverride) {
            $invoice->load(['lines.product.unit', 'supplier']);

            if (! $invoice->warehouse_id) {
                throw ValidationException::withMessages(['warehouse_id' => ['يجب تحديد المخزن قبل الترحيل.']]);
            }

            $paymentType = $invoice->payment_type ?: 'credit';
            $intendedPaid = $intendedPaidOverride;
            if ($intendedPaid === null) {
                $intendedPaid = match ($paymentType) {
                    'cash' => (float) $invoice->total,
                    'partial' => (float) $invoice->paid_amount,
                    default => 0.0,
                };
            }

            $invoice->update(['paid_amount' => 0]);

            $ap = $invoice->supplier->account_id
                ? Account::query()->findOrFail($invoice->supplier->account_id)
                : Account::query()->where('code', '2101')->firstOrFail();
            $inventory = Account::query()->where('code', '1104')->firstOrFail();
            $vatInput = Account::query()->where('code', '1106')->first()
                ?? Account::query()->where('code', '2102')->firstOrFail();

            $rate = (float) ($invoice->exchange_rate ?: 1);
            $baseTotal = (float) ($invoice->base_amount ?: round((float) $invoice->total * $rate, 2));
            $baseTax = round((float) $invoice->tax_amount * $rate, 2);
            // Inventory absorbs the sub-cent FX rounding so the entry always balances against base_amount.
            $baseSubtotal = round($baseTotal - $baseTax, 2);

            $glLines = [
                ['account_id' => $inventory->id, 'debit' => $baseSubtotal, 'credit' => 0],
            ];

            if ($baseTax > 0) {
                $glLines[] = ['account_id' => $vatInput->id, 'debit' => $baseTax, 'credit' => 0];
            }

            $glLines[] = ['account_id' => $ap->id, 'debit' => 0, 'credit' => $baseTotal];

            $entry = $this->journals->create([
                'entry_date' => $invoice->invoice_date->toDateString(),
                'branch_id' => $this->resolvePurchaseBranchId($invoice->branch_id, $invoice->warehouse_id, $invoice->supplier?->branch_id),
                'description' => 'ترحيل فاتورة مشتريات '.$invoice->invoice_number,
                'reference' => $invoice->invoice_number,
                'status' => 'posted',
            ], $glLines, $user);

            foreach ($invoice->lines as $line) {
                $product = $line->product;
                // Line costs are in the invoice currency; product cost and stock cost are kept in base currency.
                $baseUnitCost = round((float) $line->unit_cost * $rate, 4);
                $product->update([
                    'cost_price' => $this->inventory->movingAverageCost($product, (float) $line->quantity, $baseUnitCost),
                ]);

                $this->inventory->adjustStock(
                    $invoice->warehouse_id,
                    $line->product_id,
                    (float) $line->quantity,
                    'in',
                    $user,
                    [
                        'movement_date' => $invoice->invoice_date->toDateString(),
                        'unit_cost' => $baseUnitCost,
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

            if ($intendedPaid > 0) {
                $cashBoxId = $invoice->cash_box_id
                    ? (int) $invoice->cash_box_id
                    : $this->cash->resolveDefaultCashBoxId();

                if (! $cashBoxId) {
                    throw ValidationException::withMessages([
                        'cash_box_id' => ['يجب تحديد الصندوق عند الدفع نقداً أو بدفعة جزئية.'],
                    ]);
                }

                if (! $invoice->cash_box_id) {
                    $invoice->update(['cash_box_id' => $cashBoxId]);
                }

                $this->createPayment([
                    'payment_date' => $invoice->invoice_date->toDateString(),
                    'supplier_id' => $invoice->supplier_id,
                    'purchase_invoice_id' => $invoice->id,
                    'branch_id' => $invoice->branch_id,
                    'cash_box_id' => $cashBoxId,
                    'method' => 'cash',
                    'amount' => $intendedPaid,
                    'currency' => $invoice->currency,
                    'exchange_rate' => $invoice->exchange_rate,
                    'status' => 'posted',
                    'notes' => 'صرف تلقائي لفاتورة '.$invoice->invoice_number,
                ], $user);
            }

            $this->audit->log($user, 'purchase_invoice.posted', $invoice);

            return $invoice->fresh(['lines.product.unit', 'supplier', 'warehouse', 'cashBox', 'journalEntry', 'attachments']);
        });
    }

    /**
     * @return array{0: string, 1: float, 2: ?int}
     */
    protected function normalizePaymentTerms(array $data, float $total): array
    {
        $paymentType = strtolower((string) ($data['payment_type'] ?? 'credit'));
        if (! in_array($paymentType, ['cash', 'credit', 'partial'], true)) {
            throw ValidationException::withMessages(['payment_type' => ['نوع الدفع غير صالح.']]);
        }

        $cashBoxId = isset($data['cash_box_id']) && $data['cash_box_id'] !== '' && $data['cash_box_id'] !== null
            ? (int) $data['cash_box_id']
            : null;

        if (in_array($paymentType, ['cash', 'partial'], true) && ! $cashBoxId) {
            $cashBoxId = $this->cash->resolveDefaultCashBoxId();
        }

        $intendedPaid = match ($paymentType) {
            'cash' => $total,
            'partial' => round((float) ($data['paid_amount'] ?? 0), 2),
            default => 0.0,
        };

        if ($paymentType === 'partial') {
            if ($intendedPaid <= 0 || $intendedPaid >= $total) {
                throw ValidationException::withMessages([
                    'paid_amount' => ['دفعة من المبلغ يجب أن تكون أكبر من صفر وأقل من إجمالي الفاتورة.'],
                ]);
            }
        }

        if (in_array($paymentType, ['cash', 'partial'], true) && ! $cashBoxId) {
            throw ValidationException::withMessages([
                'cash_box_id' => ['يجب تحديد الصندوق عند الدفع نقداً أو بدفعة جزئية.'],
            ]);
        }

        return [$paymentType, $intendedPaid, $cashBoxId];
    }

    public function supplierBalance(Supplier $supplier): float
    {
        return (float) $this->supplierStatement($supplier)['balance'];
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

            $fx = $this->currencies->resolveDocumentFx(
                $total,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['return_date'] ?? null,
            );

            $ret = PurchaseReturn::query()->create([
                'return_number' => $this->nextNumber('PR'),
                'return_date' => $data['return_date'],
                'supplier_id' => $data['supplier_id'],
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'status' => 'draft',
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
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
                'branch_id' => $this->resolvePurchaseBranchId($ret->supplier?->branch_id, $ret->warehouse_id, $ret->supplier?->branch_id),
                'description' => 'مرتجع مشتريات '.$ret->return_number,
                'reference' => $ret->return_number,
                'status' => 'posted',
            ], [
                ['account_id' => $ap->id, 'debit' => (float) ($ret->base_amount ?: $ret->total), 'credit' => 0],
                ['account_id' => $inventory->id, 'debit' => 0, 'credit' => (float) ($ret->base_amount ?: $ret->total)],
            ], $user);

            if ($ret->warehouse_id) {
                $returnRate = (float) ($ret->exchange_rate ?: 1);

                foreach ($ret->lines as $line) {
                    $this->inventory->adjustStock(
                        $ret->warehouse_id,
                        $line->product_id,
                        -((float) $line->quantity),
                        'out',
                        $user,
                        [
                            'movement_date' => $ret->return_date->toDateString(),
                            'unit_cost' => round((float) $line->unit_cost * ($returnRate > 0 ? $returnRate : 1), 4),
                            'reference_type' => $ret::class,
                            'reference_id' => $ret->id,
                            'journal_entry_id' => $entry->id,
                            'notes' => 'مرتجع مشتريات '.$ret->return_number,
                            'batch_no' => $line->batch_no,
                            'serial_no' => $line->serial_no,
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
            $amount = (float) $data['amount'];
            $fx = $this->currencies->resolveDocumentFx(
                $amount,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['payment_date'] ?? null,
            );

            if (isset($data['base_amount']) && (float) $data['base_amount'] > 0) {
                $fx['base_amount'] = round((float) $data['base_amount'], 2);
                if ($fx['currency'] !== $this->currencies->baseCurrency() && $amount > 0) {
                    $fx['exchange_rate'] = round($fx['base_amount'] / $amount, 8);
                }
            }

            $method = $data['method'] ?? 'cash';
            $cashBoxId = isset($data['cash_box_id']) && $data['cash_box_id'] !== '' && $data['cash_box_id'] !== null
                ? (int) $data['cash_box_id']
                : null;

            // Supplier cash payments without an explicit box leave the main cash box.
            if (! $cashBoxId && $method === 'cash' && empty($data['bank_id'])) {
                $cashBoxId = $this->cash->resolveDefaultCashBoxId();
            }

            $payment = SupplierPayment::query()->create([
                'payment_number' => $this->nextNumber('SP'),
                'payment_date' => $data['payment_date'],
                'supplier_id' => $data['supplier_id'],
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'cash_box_id' => $cashBoxId,
                'bank_id' => $data['bank_id'] ?? null,
                'method' => $method,
                'amount' => $amount,
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
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

            $baseAmount = (float) ($payment->base_amount ?: $payment->amount);

            $entry = $this->journals->create([
                'entry_date' => $payment->payment_date->toDateString(),
                'branch_id' => $this->resolvePaymentBranchId($payment),
                'description' => 'سند صرف مورد '.$payment->payment_number,
                'reference' => $payment->payment_number,
                'status' => 'posted',
            ], [
                ['account_id' => $ap->id, 'debit' => $baseAmount, 'credit' => 0],
                ['account_id' => $creditAccount->id, 'debit' => 0, 'credit' => $baseAmount],
            ], $user);

            if ($payment->purchase_invoice_id) {
                $invoice = PurchaseInvoice::query()->findOrFail($payment->purchase_invoice_id);
                $invoice->increment('paid_amount', $this->paymentAmountInInvoiceCurrency($payment, $invoice));
            }

            $payment->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $payment->fresh(['supplier', 'invoice']);
        });
    }

    protected function paymentAmountInInvoiceCurrency(SupplierPayment $payment, PurchaseInvoice $invoice): float
    {
        $payCurrency = strtoupper((string) ($payment->currency ?: $this->currencies->baseCurrency()));
        $invoiceCurrency = strtoupper((string) ($invoice->currency ?: $this->currencies->baseCurrency()));

        if ($payCurrency === $invoiceCurrency) {
            return (float) $payment->amount;
        }

        $payBase = (float) ($payment->base_amount ?: round((float) $payment->amount * (float) ($payment->exchange_rate ?: 1), 2));
        $invRate = (float) ($invoice->exchange_rate ?: 1);

        return $invRate > 0 ? round($payBase / $invRate, 2) : $payBase;
    }

    /**
     * @param  bool  $requireInboundTracking  When true (invoices/returns), batch/serial are required for tracked products.
     *                                         Planning docs (requests/orders) may omit batch until goods are received.
     */
    protected function normalizeLines(array $lines, bool $requireInboundTracking = true): array
    {
        $taxEnabled = Setting::taxEnabled();
        $taxRateDefault = Setting::defaultTaxRate();
        $subtotal = 0;
        $tax = 0;
        $normalized = [];

        foreach ($lines as $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            $this->inventory->validateBatchSerial($product, $line, forOutbound: ! $requireInboundTracking);
            $qty = (float) $line['quantity'];
            $cost = (float) ($line['unit_cost'] ?? $product->cost_price);
            $rate = $taxEnabled ? (float) ($line['tax_rate'] ?? $taxRateDefault) : 0.0;
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
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines, requireInboundTracking: false);
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
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines, requireInboundTracking: false);
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
            [$subtotal, $tax, $total, $normalized] = $this->normalizeLines($lines, requireInboundTracking: false);
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

    protected function resolvePurchaseBranchId(?int $branchId, ?int $warehouseId, ?int $fallbackBranchId = null): ?int
    {
        if ($branchId) {
            return $branchId;
        }

        if ($warehouseId) {
            $warehouseBranchId = \App\Models\Warehouse::query()->whereKey($warehouseId)->value('branch_id');
            if ($warehouseBranchId) {
                return (int) $warehouseBranchId;
            }
        }

        return $fallbackBranchId ? (int) $fallbackBranchId : null;
    }

    protected function resolvePaymentBranchId(SupplierPayment $payment): ?int
    {
        if ($payment->purchase_invoice_id) {
            $invoiceBranchId = PurchaseInvoice::query()->whereKey($payment->purchase_invoice_id)->value('branch_id');
            if ($invoiceBranchId) {
                return (int) $invoiceBranchId;
            }
        }

        $supplierBranchId = Supplier::query()->whereKey($payment->supplier_id)->value('branch_id');
        if ($supplierBranchId) {
            return (int) $supplierBranchId;
        }

        if ($payment->cash_box_id) {
            $cashBoxBranchId = \App\Models\CashBox::query()->whereKey($payment->cash_box_id)->value('branch_id');
            if ($cashBoxBranchId) {
                return (int) $cashBoxBranchId;
            }
        }

        if ($payment->bank_id) {
            $bankBranchId = \App\Models\Bank::query()->whereKey($payment->bank_id)->value('branch_id');
            if ($bankBranchId) {
                return (int) $bankBranchId;
            }
        }

        return null;
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

    public function supplierStatement(Supplier $supplier, ?string $from = null, ?string $to = null): array
    {
        $events = [];

        // Every event is expressed in the system base currency, otherwise a foreign-currency
        // invoice or payment would be summed against base-currency documents.
        foreach ($supplier->invoices()->where('status', 'posted')->get() as $inv) {
            $events[] = [
                'date' => $inv->invoice_date->toDateString(),
                'type' => 'invoice',
                'number' => $inv->invoice_number,
                'currency' => $inv->currency,
                'document_amount' => (float) $inv->total,
                'debit' => 0.0,
                'credit' => $this->baseValue($inv->base_amount, $inv->total, $inv->exchange_rate),
            ];
        }

        foreach ($supplier->payments()->where('status', 'posted')->get() as $pay) {
            $events[] = [
                'date' => $pay->payment_date->toDateString(),
                'type' => 'payment',
                'number' => $pay->payment_number,
                'currency' => $pay->currency,
                'document_amount' => (float) $pay->amount,
                'debit' => $this->baseValue($pay->base_amount, $pay->amount, $pay->exchange_rate),
                'credit' => 0.0,
            ];
        }

        foreach (PurchaseReturn::query()->where('supplier_id', $supplier->id)->where('status', 'posted')->get() as $ret) {
            $events[] = [
                'date' => $ret->return_date->toDateString(),
                'type' => 'return',
                'number' => $ret->return_number,
                'currency' => $ret->currency,
                'document_amount' => (float) $ret->total,
                'debit' => $this->baseValue($ret->base_amount, $ret->total, $ret->exchange_rate),
                'credit' => 0.0,
            ];
        }

        usort($events, function ($a, $b) {
            return strcmp($a['date'], $b['date'])
                ?: (self::STATEMENT_ORDER[$a['type']] <=> self::STATEMENT_ORDER[$b['type']])
                ?: strcmp($a['number'], $b['number']);
        });

        $openingBalance = 0.0;
        $balance = 0.0;
        $rows = [];

        foreach ($events as $event) {
            // Supplier liability: invoices increase credit balance; payments reduce it.
            $balance += $event['credit'] - $event['debit'];

            if ($from && $event['date'] < $from) {
                $openingBalance = $balance;

                continue;
            }

            if ($to && $event['date'] > $to) {
                continue;
            }

            $rows[] = [
                'date' => $event['date'],
                'type' => $event['type'],
                'number' => $event['number'],
                'currency' => $event['currency'],
                'document_amount' => $event['document_amount'],
                'debit' => $event['debit'],
                'credit' => $event['credit'],
                'balance' => round($balance, 2),
            ];
        }

        if (! $from) {
            $openingBalance = 0.0;
        }

        $closingBalance = $rows === [] ? $openingBalance : (float) $rows[array_key_last($rows)]['balance'];

        return [
            'supplier' => $supplier,
            'from' => $from,
            'to' => $to,
            'currency' => $this->currencies->baseCurrency(),
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($closingBalance, 2),
            'rows' => $rows,
            'balance' => round($closingBalance, 2),
        ];
    }

    /**
     * Document value expressed in the system base currency.
     */
    protected function baseValue(mixed $baseAmount, mixed $documentAmount, mixed $exchangeRate): float
    {
        if ($baseAmount !== null && $baseAmount !== '' && (float) $baseAmount > 0) {
            return round((float) $baseAmount, 2);
        }

        $rate = (float) ($exchangeRate ?: 1);

        return round((float) $documentAmount * ($rate > 0 ? $rate : 1), 2);
    }
}
