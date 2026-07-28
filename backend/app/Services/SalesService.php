<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesOrder;
use App\Models\SalesQuote;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesService
{
    /** Same-day ordering so a statement reads invoice → return → collection. */
    protected const STATEMENT_ORDER = ['invoice' => 1, 'return' => 2, 'receipt' => 3];

    public function __construct(
        protected JournalEntryService $journals,
        protected InventoryService $inventory,
        protected AuditLogger $audit,
        protected CurrencyService $currencies,
        protected CashService $cash,
    ) {}

    public function createInvoice(array $data, array $lines, User $user): SalesInvoice
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeSalesLines($lines);

            [$paymentType, $intendedPaid, $cashBoxId] = $this->normalizePaymentTerms($data, $total);

            $fx = $this->currencies->resolveDocumentFx(
                $total,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['invoice_date'] ?? null,
            );

            if (($data['status'] ?? 'draft') === 'posted') {
                // Credit limit applies to the unpaid remainder, expressed in base currency.
                $this->assertCustomerCreditLimit(
                    (int) $data['customer_id'],
                    round(max(0, $total - $intendedPaid) * (float) $fx['exchange_rate'], 2)
                );
            }

            $warehouseId = $this->resolveWarehouseId(isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null);

            $invoice = SalesInvoice::query()->create([
                'invoice_number' => $this->nextNumber('SI'),
                'e_invoice_uuid' => (string) Str::uuid(),
                'invoice_date' => $data['invoice_date'],
                'customer_id' => $data['customer_id'],
                'warehouse_id' => $warehouseId,
                'cash_box_id' => $cashBoxId,
                'branch_id' => $data['branch_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'status' => 'draft',
                'payment_type' => $paymentType,
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'paid_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($normalized as $line) {
                $invoice->lines()->create($line);
            }

            if (($data['status'] ?? 'draft') === 'posted') {
                return $this->postInvoice($invoice, $user, $intendedPaid);
            }

            // Persist intended paid for draft partials/cash until posting.
            if (in_array($paymentType, ['cash', 'partial'], true)) {
                $invoice->update(['paid_amount' => $intendedPaid]);
            }

            return $invoice->load(['lines.product.unit', 'customer', 'warehouse', 'cashBox', 'attachments']);
        });
    }

    public function postInvoice(SalesInvoice $invoice, User $user, ?float $intendedPaidOverride = null): SalesInvoice
    {
        if ($invoice->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['الفاتورة مرحّلة مسبقاً.']]);
        }

        return DB::transaction(function () use ($invoice, $user, $intendedPaidOverride) {
            $invoice->load(['lines.product.unit', 'customer']);

            if ($invoice->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => ['الفاتورة بلا بنود.']]);
            }

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

            // Reset paid_amount before auto-receipt increments it.
            $invoice->update(['paid_amount' => 0]);

            // Base currency, otherwise a foreign-currency total is measured against a base-currency limit.
            $this->assertCustomerCreditLimit(
                (int) $invoice->customer_id,
                round(max(0, (float) $invoice->total - $intendedPaid) * (float) ($invoice->exchange_rate ?: 1), 2)
            );

            $customer = $invoice->customer;
            $arAccount = $customer->account_id
                ? Account::query()->findOrFail($customer->account_id)
                : Account::query()->where('code', '1103')->firstOrFail();
            $salesAccount = Account::query()->where('code', '4101')->firstOrFail();
            $vatAccount = Account::query()->where('code', '2102')->firstOrFail();
            $cogsAccount = Account::query()->where('code', '5101')->firstOrFail();
            $inventoryAccount = Account::query()->where('code', '1104')->firstOrFail();

            $rate = (float) ($invoice->exchange_rate ?: 1);
            $baseTotal = (float) ($invoice->base_amount ?: round((float) $invoice->total * $rate, 2));
            $baseTax = round((float) $invoice->tax_amount * $rate, 2);
            // Revenue absorbs the sub-cent FX rounding so the entry always balances against base_amount.
            $baseSubtotal = round($baseTotal - $baseTax, 2);

            $glLines = [
                ['account_id' => $arAccount->id, 'debit' => $baseTotal, 'credit' => 0, 'memo' => 'فاتورة '.$invoice->invoice_number],
                ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => $baseSubtotal],
            ];

            if ($baseTax > 0) {
                $glLines[] = ['account_id' => $vatAccount->id, 'debit' => 0, 'credit' => $baseTax];
            }

            $cogsTotal = 0.0;
            foreach ($invoice->lines as $line) {
                $this->inventory->assertSufficientStock(
                    (int) $invoice->warehouse_id,
                    (int) $line->product_id,
                    (float) $line->quantity,
                    $line->batch_no,
                    $line->product
                );

                $resolvedBatch = $this->inventory->resolveOutboundBatch(
                    (int) $invoice->warehouse_id,
                    $line->product,
                    (float) $line->quantity,
                    $line->batch_no
                );

                $cost = round((float) $line->quantity * (float) $line->cost_price, 2);
                $cogsTotal += $cost;

                $movement = $this->inventory->adjustStock(
                    $invoice->warehouse_id,
                    $line->product_id,
                    -((float) $line->quantity),
                    'out',
                    $user,
                    [
                        'movement_date' => $invoice->invoice_date->toDateString(),
                        'unit_cost' => $line->cost_price,
                        'batch_no' => $resolvedBatch ?? $line->batch_no,
                        'serial_no' => $line->serial_no,
                        'reference_type' => $invoice::class,
                        'reference_id' => $invoice->id,
                        'notes' => 'صرف مبيعات '.$invoice->invoice_number,
                    ]
                );

                $actualBatch = $movement->batch_no ?? $resolvedBatch;
                if ($line->product->track_batch && $actualBatch && $actualBatch !== $line->batch_no) {
                    $line->update(['batch_no' => $actualBatch]);
                }
            }

            if ($cogsTotal > 0) {
                $glLines[] = ['account_id' => $cogsAccount->id, 'debit' => $cogsTotal, 'credit' => 0];
                $glLines[] = ['account_id' => $inventoryAccount->id, 'debit' => 0, 'credit' => $cogsTotal];
            }

            $entry = $this->journals->create([
                'entry_date' => $invoice->invoice_date->toDateString(),
                'branch_id' => $this->resolveSalesBranchId($invoice->branch_id, $invoice->warehouse_id, $invoice->customer?->branch_id),
                'description' => 'ترحيل فاتورة مبيعات '.$invoice->invoice_number,
                'reference' => $invoice->invoice_number,
                'status' => 'posted',
            ], $glLines, $user);

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

                $this->createReceipt([
                    'receipt_date' => $invoice->invoice_date->toDateString(),
                    'customer_id' => $invoice->customer_id,
                    'sales_invoice_id' => $invoice->id,
                    'branch_id' => $invoice->branch_id,
                    'cash_box_id' => $cashBoxId,
                    'method' => 'cash',
                    'amount' => $intendedPaid,
                    'currency' => $invoice->currency,
                    'exchange_rate' => $invoice->exchange_rate,
                    'status' => 'posted',
                    'notes' => 'تحصيل تلقائي لفاتورة '.$invoice->invoice_number,
                ], $user);
            }

            $this->audit->log($user, 'sales_invoice.posted', $invoice);

            return $invoice->fresh(['lines.product.unit', 'customer', 'warehouse', 'cashBox', 'journalEntry', 'attachments']);
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

    public function customerBalance(Customer $customer): float
    {
        return (float) $this->customerStatement($customer)['balance'];
    }

    public function createReturn(array $data, array $lines, User $user): SalesReturn
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            $total = 0;
            $normalized = [];
            foreach ($lines as $line) {
                $lineTotal = round((float) $line['quantity'] * (float) $line['unit_price'], 2);
                $total += $lineTotal;
                $normalized[] = [
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $lineTotal,
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

            $ret = SalesReturn::query()->create([
                'return_number' => $this->nextNumber('SR'),
                'return_date' => $data['return_date'],
                'customer_id' => $data['customer_id'],
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
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

            return $ret->load(['lines.product', 'customer']);
        });
    }

    public function postReturn(SalesReturn $ret, User $user): SalesReturn
    {
        if ($ret->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['المرتجع مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($ret, $user) {
            $ret->load(['lines.product', 'customer']);
            $customer = $ret->customer;
            $ar = $customer->account_id
                ? Account::query()->findOrFail($customer->account_id)
                : Account::query()->where('code', '1103')->firstOrFail();
            $sales = Account::query()->where('code', '4101')->firstOrFail();

            $baseAmount = (float) ($ret->base_amount ?: $ret->total);

            $glLines = [
                ['account_id' => $sales->id, 'debit' => $baseAmount, 'credit' => 0],
                ['account_id' => $ar->id, 'debit' => 0, 'credit' => $baseAmount],
            ];

            // Returned goods come back into stock, so inventory and COGS must be reversed too.
            $returnedCost = $ret->warehouse_id ? $this->returnedGoodsCost($ret) : 0.0;

            if ($returnedCost > 0) {
                $inventoryAccount = Account::query()->where('code', '1104')->firstOrFail();
                $cogsAccount = Account::query()->where('code', '5101')->firstOrFail();
                $glLines[] = ['account_id' => $inventoryAccount->id, 'debit' => $returnedCost, 'credit' => 0, 'memo' => 'إرجاع تكلفة بضاعة'];
                $glLines[] = ['account_id' => $cogsAccount->id, 'debit' => 0, 'credit' => $returnedCost, 'memo' => 'عكس تكلفة البضاعة المباعة'];
            }

            $entry = $this->journals->create([
                'entry_date' => $ret->return_date->toDateString(),
                'branch_id' => $this->resolveSalesBranchId($ret->customer?->branch_id, $ret->warehouse_id, $ret->customer?->branch_id),
                'description' => 'مرتجع مبيعات '.$ret->return_number,
                'reference' => $ret->return_number,
                'status' => 'posted',
            ], $glLines, $user);

            if ($ret->warehouse_id) {
                foreach ($ret->lines as $line) {
                    $this->inventory->adjustStock(
                        $ret->warehouse_id,
                        $line->product_id,
                        (float) $line->quantity,
                        'in',
                        $user,
                        [
                            'movement_date' => $ret->return_date->toDateString(),
                            'unit_cost' => $this->returnedUnitCost($ret, $line),
                            'reference_type' => $ret::class,
                            'reference_id' => $ret->id,
                            'journal_entry_id' => $entry->id,
                            'notes' => 'مرتجع مبيعات '.$ret->return_number,
                        ]
                    );
                }
            }

            $ret->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $ret->fresh(['lines.product', 'customer']);
        });
    }

    protected function returnedGoodsCost(SalesReturn $ret): float
    {
        $cost = 0.0;

        foreach ($ret->lines as $line) {
            $cost += round((float) $line->quantity * $this->returnedUnitCost($ret, $line), 2);
        }

        return round($cost, 2);
    }

    /**
     * Cost per unit for returned goods: the cost captured on the original invoice line
     * when the return is linked to an invoice, otherwise the product's current cost.
     */
    protected function returnedUnitCost(SalesReturn $ret, SalesReturnLine $line): float
    {
        if ($ret->sales_invoice_id) {
            $invoiceCost = SalesInvoiceLine::query()
                ->where('sales_invoice_id', $ret->sales_invoice_id)
                ->where('product_id', $line->product_id)
                ->value('cost_price');

            if ($invoiceCost !== null) {
                return (float) $invoiceCost;
            }
        }

        return (float) ($line->product?->cost_price ?? Product::query()->whereKey($line->product_id)->value('cost_price') ?? 0);
    }

    public function createReceipt(array $data, User $user): Receipt
    {
        return DB::transaction(function () use ($data, $user) {
            $amount = (float) $data['amount'];
            $fx = $this->currencies->resolveDocumentFx(
                $amount,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['receipt_date'] ?? null,
            );

            // Allow explicit base_amount override (e.g. user entered SYP and converted)
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

            // Cash collections without an explicit box go to the main cash box.
            if (! $cashBoxId && $method === 'cash' && empty($data['bank_id'])) {
                $cashBoxId = $this->cash->resolveDefaultCashBoxId();
            }

            $receipt = Receipt::query()->create([
                'receipt_number' => $this->nextNumber('RC'),
                'receipt_date' => $data['receipt_date'],
                'customer_id' => $data['customer_id'],
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
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
                return $this->postReceipt($receipt, $user);
            }

            return $receipt->load(['customer', 'invoice']);
        });
    }

    /**
     * Collect remaining unpaid balance on a posted sales invoice (credit/partial).
     * Creates a posted customer receipt allocated to the invoice.
     */
    public function collectRemaining(SalesInvoice $invoice, User $user, array $data = []): Receipt
    {
        if ($invoice->status !== 'posted') {
            throw ValidationException::withMessages(['status' => ['يجب أن تكون الفاتورة مرحّلة أولاً.']]);
        }

        $remaining = round((float) $invoice->total - (float) $invoice->paid_amount, 2);
        if ($remaining <= 0) {
            throw ValidationException::withMessages(['amount' => ['لا يوجد مبلغ متبقّي على هذه الفاتورة.']]);
        }

        $amount = array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== ''
            ? round((float) $data['amount'], 2)
            : $remaining;

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['المبلغ يجب أن يكون أكبر من صفر.']]);
        }

        if ($amount > $remaining + 0.001) {
            throw ValidationException::withMessages([
                'amount' => ['المبلغ يتجاوز المتبقي على الفاتورة ('.$remaining.').'],
            ]);
        }

        return $this->createReceipt([
            'receipt_date' => $data['receipt_date'] ?? now()->toDateString(),
            'customer_id' => $invoice->customer_id,
            'sales_invoice_id' => $invoice->id,
            'cash_box_id' => $data['cash_box_id'] ?? $invoice->cash_box_id,
            'bank_id' => $data['bank_id'] ?? null,
            'method' => $data['method'] ?? 'cash',
            'amount' => $amount,
            'currency' => $data['currency'] ?? $invoice->currency,
            'exchange_rate' => isset($data['exchange_rate'])
                ? (float) $data['exchange_rate']
                : ($invoice->exchange_rate ? (float) $invoice->exchange_rate : null),
            'base_amount' => $data['base_amount'] ?? null,
            'notes' => $data['notes'] ?? ('تحصيل باقي فاتورة '.$invoice->invoice_number),
            'status' => 'posted',
        ], $user);
    }

    public function postReceipt(Receipt $receipt, User $user): Receipt
    {
        if ($receipt->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['سند القبض مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($receipt, $user) {
            $receipt->load('customer');
            $cashAccountCode = $receipt->method === 'bank' ? '1102' : '1101';

            if ($receipt->method === 'bank' && $receipt->bank_id) {
                $bank = \App\Models\Bank::query()->findOrFail($receipt->bank_id);
                $debitAccount = $bank->account_id
                    ? Account::query()->findOrFail($bank->account_id)
                    : Account::query()->where('code', '1102')->firstOrFail();
            } elseif ($receipt->cash_box_id) {
                $box = \App\Models\CashBox::query()->findOrFail($receipt->cash_box_id);
                $debitAccount = $box->account_id
                    ? Account::query()->findOrFail($box->account_id)
                    : Account::query()->where('code', $cashAccountCode)->firstOrFail();
            } else {
                $debitAccount = Account::query()->where('code', $cashAccountCode)->firstOrFail();
            }

            $ar = $receipt->customer->account_id
                ? Account::query()->findOrFail($receipt->customer->account_id)
                : Account::query()->where('code', '1103')->firstOrFail();

            $baseAmount = (float) ($receipt->base_amount ?: $receipt->amount);

            if ($receipt->sales_invoice_id) {
                $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($receipt->sales_invoice_id);
                if ((int) $invoice->customer_id !== (int) $receipt->customer_id) {
                    throw ValidationException::withMessages([
                        'sales_invoice_id' => ['الفاتورة لا تخص هذا العميل.'],
                    ]);
                }
                if ($invoice->status !== 'posted') {
                    throw ValidationException::withMessages([
                        'sales_invoice_id' => ['لا يمكن التحصيل على فاتورة غير مرحّلة.'],
                    ]);
                }
                $applied = $this->receiptAmountInInvoiceCurrency($receipt, $invoice);
                $remaining = round((float) $invoice->total - (float) $invoice->paid_amount, 2);
                if ($applied > $remaining + 0.001) {
                    throw ValidationException::withMessages([
                        'amount' => ['المبلغ يتجاوز المتبقي على الفاتورة ('.$remaining.').'],
                    ]);
                }
            }

            $entry = $this->journals->create([
                'entry_date' => $receipt->receipt_date->toDateString(),
                'branch_id' => $this->resolveReceiptBranchId($receipt),
                'description' => 'سند قبض '.$receipt->receipt_number,
                'reference' => $receipt->receipt_number,
                'status' => 'posted',
            ], [
                ['account_id' => $debitAccount->id, 'debit' => $baseAmount, 'credit' => 0],
                ['account_id' => $ar->id, 'debit' => 0, 'credit' => $baseAmount],
            ], $user);

            if ($receipt->sales_invoice_id) {
                $invoice = SalesInvoice::query()->findOrFail($receipt->sales_invoice_id);
                $invoice->increment('paid_amount', $this->receiptAmountInInvoiceCurrency($receipt, $invoice));
            }

            $receipt->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $receipt->fresh(['customer', 'invoice']);
        });
    }

    protected function receiptAmountInInvoiceCurrency(Receipt $receipt, SalesInvoice $invoice): float
    {
        $receiptCurrency = strtoupper((string) ($receipt->currency ?: $this->currencies->baseCurrency()));
        $invoiceCurrency = strtoupper((string) ($invoice->currency ?: $this->currencies->baseCurrency()));

        if ($receiptCurrency === $invoiceCurrency) {
            return (float) $receipt->amount;
        }

        $receiptBase = (float) ($receipt->base_amount ?: round((float) $receipt->amount * (float) ($receipt->exchange_rate ?: 1), 2));
        $invRate = (float) ($invoice->exchange_rate ?: 1);

        return $invRate > 0 ? round($receiptBase / $invRate, 2) : $receiptBase;
    }

    protected function normalizeSalesLines(array $lines): array
    {
        $taxEnabled = Setting::taxEnabled();
        $taxRateDefault = Setting::defaultTaxRate();
        $subtotal = 0;
        $tax = 0;
        $normalized = [];

        foreach ($lines as $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            $this->inventory->validateBatchSerial($product, $line, forOutbound: true);
            $qty = (float) $line['quantity'];
            $price = (float) ($line['unit_price'] ?? $product->sale_price);
            $rate = $taxEnabled ? (float) ($line['tax_rate'] ?? $taxRateDefault) : 0.0;
            $lineSub = round($qty * $price, 2);
            $lineTax = round($lineSub * $rate / 100, 2);
            $subtotal += $lineSub;
            $tax += $lineTax;
            $normalized[] = [
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'tax_rate' => $rate,
                'line_total' => round($lineSub + $lineTax, 2),
                'cost_price' => (float) ($line['cost_price'] ?? $product->cost_price),
                'batch_no' => $line['batch_no'] ?? null,
                'serial_no' => $line['serial_no'] ?? null,
            ];
        }

        return [$subtotal, $tax, round($subtotal + $tax, 2), $normalized];
    }

    public function createQuote(array $data, array $lines, User $user): SalesQuote
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeSalesLines($lines);
            $fx = $this->currencies->resolveDocumentFx($total, $data['currency'] ?? null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null, $data['quote_date'] ?? null);

            $quote = SalesQuote::query()->create([
                'quote_number' => $this->nextNumber('SQ'),
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'customer_id' => $data['customer_id'],
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
                $quote->items()->create($line);
            }

            return $quote->load(['items.product', 'customer', 'warehouse']);
        });
    }

    public function updateQuote(SalesQuote $quote, array $data, array $lines, User $user): SalesQuote
    {
        if (in_array($quote->status, ['converted', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['لا يمكن تعديل عرض محوّل أو ملغى.']]);
        }

        return DB::transaction(function () use ($quote, $data, $lines) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeSalesLines($lines);
            $quote->update([
                'quote_date' => $data['quote_date'] ?? $quote->quote_date,
                'valid_until' => $data['valid_until'] ?? $quote->valid_until,
                'customer_id' => $data['customer_id'] ?? $quote->customer_id,
                'warehouse_id' => $data['warehouse_id'] ?? $quote->warehouse_id,
                'notes' => $data['notes'] ?? $quote->notes,
                'status' => $data['status'] ?? $quote->status,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total' => $total,
            ]);
            $quote->items()->delete();
            foreach ($normalized as $line) {
                $quote->items()->create($line);
            }

            return $quote->fresh(['items.product', 'customer', 'warehouse']);
        });
    }

    public function convertQuoteToOrder(SalesQuote $quote, User $user, array $overrides = []): SalesOrder
    {
        if ($quote->status === 'converted') {
            throw ValidationException::withMessages(['status' => ['عرض السعر محوّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($quote, $user, $overrides) {
            $quote->load('items');
            $order = SalesOrder::query()->create([
                'order_number' => $this->nextNumber('SO'),
                'order_date' => $overrides['order_date'] ?? now()->toDateString(),
                'customer_id' => $quote->customer_id,
                'sales_quote_id' => $quote->id,
                'warehouse_id' => $overrides['warehouse_id'] ?? $quote->warehouse_id,
                'branch_id' => $quote->branch_id,
                'status' => 'confirmed',
                'currency' => $quote->currency,
                'exchange_rate' => $quote->exchange_rate,
                'base_amount' => $quote->base_amount,
                'subtotal' => $quote->subtotal,
                'tax_amount' => $quote->tax_amount,
                'total' => $quote->total,
                'notes' => $quote->notes,
                'created_by' => $user->id,
            ]);

            foreach ($quote->items as $item) {
                $order->items()->create($item->only([
                    'product_id', 'quantity', 'unit_price', 'tax_rate', 'line_total', 'batch_no', 'serial_no',
                ]));
            }

            $quote->update(['status' => 'converted']);

            return $order->load(['items.product', 'customer', 'quote']);
        });
    }

    public function createOrder(array $data, array $lines, User $user): SalesOrder
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeSalesLines($lines);
            $fx = $this->currencies->resolveDocumentFx($total, $data['currency'] ?? null, isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null, $data['order_date'] ?? null);

            $order = SalesOrder::query()->create([
                'order_number' => $this->nextNumber('SO'),
                'order_date' => $data['order_date'],
                'customer_id' => $data['customer_id'],
                'sales_quote_id' => $data['sales_quote_id'] ?? null,
                'warehouse_id' => $this->resolveWarehouseId(isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null),
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

            return $order->load(['items.product', 'customer', 'warehouse']);
        });
    }

    public function convertOrderToInvoice(SalesOrder $order, User $user, array $overrides = []): SalesInvoice
    {
        if ($order->status === 'converted') {
            throw ValidationException::withMessages(['status' => ['أمر البيع محوّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($order, $user, $overrides) {
            $order->load(['items.product']);

            $warehouseId = $this->resolveWarehouseId(
                isset($overrides['warehouse_id']) ? (int) $overrides['warehouse_id'] : ($order->warehouse_id ? (int) $order->warehouse_id : null)
            );

            $lines = $order->items->map(function ($item) use ($warehouseId) {
                $batchNo = $item->batch_no;
                $product = $item->product;

                if ($warehouseId && $product && $product->track_batch) {
                    $batchNo = $this->inventory->resolveOutboundBatch(
                        $warehouseId,
                        $product,
                        (float) $item->quantity,
                        $item->batch_no
                    );
                }

                return [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'batch_no' => $batchNo,
                    'serial_no' => $item->serial_no,
                ];
            })->all();

            $invoice = $this->createInvoice([
                'invoice_date' => $overrides['invoice_date'] ?? now()->toDateString(),
                'customer_id' => $order->customer_id,
                'warehouse_id' => $warehouseId,
                'branch_id' => $order->branch_id,
                'sales_order_id' => $order->id,
                'currency' => $order->currency,
                'exchange_rate' => $order->exchange_rate,
                'status' => $overrides['status'] ?? 'draft',
                'notes' => $order->notes,
            ], $lines, $user);

            $order->update(['status' => 'converted']);

            return $invoice;
        });
    }

    public function nextNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $full = "{$prefix}-{$year}-";

        $last = match ($prefix) {
            'SI' => SalesInvoice::query()->where('invoice_number', 'like', $full.'%')->orderByDesc('invoice_number')->value('invoice_number'),
            'SR' => SalesReturn::query()->where('return_number', 'like', $full.'%')->orderByDesc('return_number')->value('return_number'),
            'RC' => Receipt::query()->where('receipt_number', 'like', $full.'%')->orderByDesc('receipt_number')->value('receipt_number'),
            'SQ' => SalesQuote::query()->where('quote_number', 'like', $full.'%')->orderByDesc('quote_number')->value('quote_number'),
            'SO' => SalesOrder::query()->where('order_number', 'like', $full.'%')->orderByDesc('order_number')->value('order_number'),
            default => null,
        };

        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public function customerStatement(Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $events = [];

        // Every event is expressed in the system base currency, otherwise a foreign-currency
        // receipt would be summed against base-currency invoices.
        foreach ($customer->invoices()->where('status', 'posted')->get() as $inv) {
            $events[] = [
                'date' => $inv->invoice_date->toDateString(),
                'type' => 'invoice',
                'number' => $inv->invoice_number,
                'currency' => $inv->currency,
                'document_amount' => (float) $inv->total,
                'debit' => $this->baseValue($inv->base_amount, $inv->total, $inv->exchange_rate),
                'credit' => 0.0,
            ];
        }

        foreach ($customer->receipts()->where('status', 'posted')->get() as $rc) {
            $events[] = [
                'date' => $rc->receipt_date->toDateString(),
                'type' => 'receipt',
                'number' => $rc->receipt_number,
                'currency' => $rc->currency,
                'document_amount' => (float) $rc->amount,
                'debit' => 0.0,
                'credit' => $this->baseValue($rc->base_amount, $rc->amount, $rc->exchange_rate),
            ];
        }

        foreach (SalesReturn::query()->where('customer_id', $customer->id)->where('status', 'posted')->get() as $ret) {
            $events[] = [
                'date' => $ret->return_date->toDateString(),
                'type' => 'return',
                'number' => $ret->return_number,
                'currency' => $ret->currency,
                'document_amount' => (float) $ret->total,
                'debit' => 0.0,
                'credit' => $this->baseValue($ret->base_amount, $ret->total, $ret->exchange_rate),
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
            $balance += $event['debit'] - $event['credit'];

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
            'customer' => $customer,
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

    public function deleteQuote(SalesQuote $quote): void
    {
        if ($quote->status === 'converted') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف عرض محوّل.'],
            ]);
        }

        DB::transaction(function () use ($quote) {
            $quote->items()->delete();
            $quote->delete();
        });
    }

    public function deleteOrder(SalesOrder $order): void
    {
        if ($order->status === 'converted') {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف أمر محوّل.'],
            ]);
        }

        if ($order->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['يمكن حذف أوامر البيع المسودة فقط.'],
            ]);
        }

        DB::transaction(function () use ($order) {
            $order->items()->delete();
            $order->delete();
        });
    }

    public function deleteInvoice(SalesInvoice $invoice): void
    {
        $this->assertDraftNotPosted($invoice->status);

        DB::transaction(function () use ($invoice) {
            $invoice->lines()->delete();
            $invoice->delete();
        });
    }

    public function deleteReturn(SalesReturn $ret): void
    {
        $this->assertDraftNotPosted($ret->status);

        DB::transaction(function () use ($ret) {
            $ret->lines()->delete();
            $ret->delete();
        });
    }

    public function deleteReceipt(Receipt $receipt): void
    {
        $this->assertDraftNotPosted($receipt->status);

        if ($receipt->journal_entry_id) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن حذف سند مرتبط بقيد محاسبي.'],
            ]);
        }

        $receipt->delete();
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

    protected function resolveWarehouseId(?int $warehouseId): ?int
    {
        if ($warehouseId) {
            return $warehouseId;
        }

        $default = Setting::getValue('default_warehouse_id');

        return $default ? (int) $default : null;
    }

    protected function resolveSalesBranchId(?int $branchId, ?int $warehouseId, ?int $fallbackBranchId = null): ?int
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

    protected function resolveReceiptBranchId(Receipt $receipt): ?int
    {
        if ($receipt->sales_invoice_id) {
            $invoiceBranchId = SalesInvoice::query()->whereKey($receipt->sales_invoice_id)->value('branch_id');
            if ($invoiceBranchId) {
                return (int) $invoiceBranchId;
            }
        }

        $customerBranchId = Customer::query()->whereKey($receipt->customer_id)->value('branch_id');
        if ($customerBranchId) {
            return (int) $customerBranchId;
        }

        if ($receipt->cash_box_id) {
            $cashBoxBranchId = \App\Models\CashBox::query()->whereKey($receipt->cash_box_id)->value('branch_id');
            if ($cashBoxBranchId) {
                return (int) $cashBoxBranchId;
            }
        }

        if ($receipt->bank_id) {
            $bankBranchId = \App\Models\Bank::query()->whereKey($receipt->bank_id)->value('branch_id');
            if ($bankBranchId) {
                return (int) $bankBranchId;
            }
        }

        return null;
    }

    protected function assertCustomerCreditLimit(int $customerId, float $additionalAmount): void
    {
        $customer = Customer::query()->findOrFail($customerId);
        $limit = (float) $customer->credit_limit;

        if ($limit <= 0) {
            return;
        }

        $statement = $this->customerStatement($customer);
        $projected = (float) $statement['balance'] + $additionalAmount;

        if ($projected > $limit) {
            throw ValidationException::withMessages([
                'customer_id' => [
                    sprintf(
                        'تجاوز حد الائتمان للعميل (%s). الرصيد الحالي: %s — الحد: %s (%s)',
                        $customer->name,
                        number_format((float) $statement['balance'], 2),
                        number_format($limit, 2),
                        $this->currencies->baseCurrency(),
                    ),
                ],
            ]);
        }
    }
}
