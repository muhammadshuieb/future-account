<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Product;
use App\Models\PurchaseInvoice;
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
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
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
            ];
        }

        return [$subtotal, $tax, round($subtotal + $tax, 2), $normalized];
    }

    public function nextNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $full = "{$prefix}-{$year}-";

        $last = match ($prefix) {
            'PI' => PurchaseInvoice::query()->where('invoice_number', 'like', $full.'%')->orderByDesc('invoice_number')->value('invoice_number'),
            'PR' => PurchaseReturn::query()->where('return_number', 'like', $full.'%')->orderByDesc('return_number')->value('return_number'),
            'SP' => SupplierPayment::query()->where('payment_number', 'like', $full.'%')->orderByDesc('payment_number')->value('payment_number'),
            default => null,
        };

        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
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
