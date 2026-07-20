<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(
        protected JournalEntryService $journals,
        protected InventoryService $inventory,
        protected AuditLogger $audit,
        protected CurrencyService $currencies,
    ) {}

    public function createInvoice(array $data, array $lines, User $user): SalesInvoice
    {
        return DB::transaction(function () use ($data, $lines, $user) {
            [$subtotal, $tax, $total, $normalized] = $this->normalizeSalesLines($lines);

            $fx = $this->currencies->resolveDocumentFx(
                $total,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['invoice_date'] ?? null,
            );

            $invoice = SalesInvoice::query()->create([
                'invoice_number' => $this->nextNumber('SI'),
                'invoice_date' => $data['invoice_date'],
                'customer_id' => $data['customer_id'],
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

            return $invoice->load(['lines.product', 'customer', 'warehouse']);
        });
    }

    public function postInvoice(SalesInvoice $invoice, User $user): SalesInvoice
    {
        if ($invoice->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['الفاتورة مرحّلة مسبقاً.']]);
        }

        return DB::transaction(function () use ($invoice, $user) {
            $invoice->load(['lines.product', 'customer']);

            if ($invoice->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => ['الفاتورة بلا بنود.']]);
            }

            if (! $invoice->warehouse_id) {
                throw ValidationException::withMessages(['warehouse_id' => ['يجب تحديد المخزن قبل الترحيل.']]);
            }

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
            $baseSubtotal = round((float) $invoice->subtotal * $rate, 2);
            $baseTax = round((float) $invoice->tax_amount * $rate, 2);

            $glLines = [
                ['account_id' => $arAccount->id, 'debit' => $baseTotal, 'credit' => 0, 'memo' => 'فاتورة '.$invoice->invoice_number],
                ['account_id' => $salesAccount->id, 'debit' => 0, 'credit' => $baseSubtotal],
            ];

            if ($baseTax > 0) {
                $glLines[] = ['account_id' => $vatAccount->id, 'debit' => 0, 'credit' => $baseTax];
            }

            $cogsTotal = 0.0;
            foreach ($invoice->lines as $line) {
                $cost = round((float) $line->quantity * (float) $line->cost_price, 2);
                $cogsTotal += $cost;

                $this->inventory->adjustStock(
                    $invoice->warehouse_id,
                    $line->product_id,
                    -((float) $line->quantity),
                    'out',
                    $user,
                    [
                        'movement_date' => $invoice->invoice_date->toDateString(),
                        'unit_cost' => $line->cost_price,
                        'reference_type' => $invoice::class,
                        'reference_id' => $invoice->id,
                        'notes' => 'صرف مبيعات '.$invoice->invoice_number,
                    ]
                );
            }

            if ($cogsTotal > 0) {
                $glLines[] = ['account_id' => $cogsAccount->id, 'debit' => $cogsTotal, 'credit' => 0];
                $glLines[] = ['account_id' => $inventoryAccount->id, 'debit' => 0, 'credit' => $cogsTotal];
            }

            $entry = $this->journals->create([
                'entry_date' => $invoice->invoice_date->toDateString(),
                'description' => 'ترحيل فاتورة مبيعات '.$invoice->invoice_number,
                'reference' => $invoice->invoice_number,
                'status' => 'posted',
            ], $glLines, $user);

            $invoice->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'posted_at' => now(),
            ]);

            $this->audit->log($user, 'sales_invoice.posted', $invoice);

            return $invoice->fresh(['lines.product', 'customer', 'warehouse', 'journalEntry']);
        });
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
                ];
            }

            $ret = SalesReturn::query()->create([
                'return_number' => $this->nextNumber('SR'),
                'return_date' => $data['return_date'],
                'customer_id' => $data['customer_id'],
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
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

            $entry = $this->journals->create([
                'entry_date' => $ret->return_date->toDateString(),
                'description' => 'مرتجع مبيعات '.$ret->return_number,
                'reference' => $ret->return_number,
                'status' => 'posted',
            ], [
                ['account_id' => $sales->id, 'debit' => (float) $ret->total, 'credit' => 0],
                ['account_id' => $ar->id, 'debit' => 0, 'credit' => (float) $ret->total],
            ], $user);

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
                            'reference_type' => $ret::class,
                            'reference_id' => $ret->id,
                            'notes' => 'مرتجع مبيعات '.$ret->return_number,
                        ]
                    );
                }
            }

            $ret->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $ret->fresh(['lines.product', 'customer']);
        });
    }

    public function createReceipt(array $data, User $user): Receipt
    {
        return DB::transaction(function () use ($data, $user) {
            $receipt = Receipt::query()->create([
                'receipt_number' => $this->nextNumber('RC'),
                'receipt_date' => $data['receipt_date'],
                'customer_id' => $data['customer_id'],
                'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
                'cash_box_id' => $data['cash_box_id'] ?? null,
                'bank_id' => $data['bank_id'] ?? null,
                'method' => $data['method'] ?? 'cash',
                'amount' => $data['amount'],
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

            $entry = $this->journals->create([
                'entry_date' => $receipt->receipt_date->toDateString(),
                'description' => 'سند قبض '.$receipt->receipt_number,
                'reference' => $receipt->receipt_number,
                'status' => 'posted',
            ], [
                ['account_id' => $debitAccount->id, 'debit' => (float) $receipt->amount, 'credit' => 0],
                ['account_id' => $ar->id, 'debit' => 0, 'credit' => (float) $receipt->amount],
            ], $user);

            if ($receipt->sales_invoice_id) {
                $invoice = SalesInvoice::query()->findOrFail($receipt->sales_invoice_id);
                $invoice->increment('paid_amount', (float) $receipt->amount);
            }

            $receipt->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $receipt->fresh(['customer', 'invoice']);
        });
    }

    protected function normalizeSalesLines(array $lines): array
    {
        $taxRateDefault = (float) (Setting::query()->where('key', 'tax_rate')->value('value') ?? 15);
        $subtotal = 0;
        $tax = 0;
        $normalized = [];

        foreach ($lines as $line) {
            $product = Product::query()->findOrFail($line['product_id']);
            $qty = (float) $line['quantity'];
            $price = (float) ($line['unit_price'] ?? $product->sale_price);
            $rate = (float) ($line['tax_rate'] ?? $taxRateDefault);
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
            ];
        }

        return [$subtotal, $tax, round($subtotal + $tax, 2), $normalized];
    }

    public function nextNumber(string $prefix): string
    {
        $year = now()->format('Y');
        $full = "{$prefix}-{$year}-";

        $last = match ($prefix) {
            'SI' => SalesInvoice::query()->where('invoice_number', 'like', $full.'%')->orderByDesc('invoice_number')->value('invoice_number'),
            'SR' => SalesReturn::query()->where('return_number', 'like', $full.'%')->orderByDesc('return_number')->value('return_number'),
            'RC' => Receipt::query()->where('receipt_number', 'like', $full.'%')->orderByDesc('receipt_number')->value('receipt_number'),
            default => null,
        };

        $seq = $last ? ((int) substr($last, strlen($full))) + 1 : 1;

        return $full.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    public function customerStatement(Customer $customer): array
    {
        $invoices = $customer->invoices()->where('status', 'posted')->orderBy('invoice_date')->get();
        $receipts = $customer->receipts()->where('status', 'posted')->orderBy('receipt_date')->get();

        $rows = [];
        $balance = 0;

        foreach ($invoices as $inv) {
            $balance += (float) $inv->total;
            $rows[] = [
                'date' => $inv->invoice_date->toDateString(),
                'type' => 'invoice',
                'number' => $inv->invoice_number,
                'debit' => (float) $inv->total,
                'credit' => 0,
                'balance' => $balance,
            ];
        }

        foreach ($receipts as $rc) {
            $balance -= (float) $rc->amount;
            $rows[] = [
                'date' => $rc->receipt_date->toDateString(),
                'type' => 'receipt',
                'number' => $rc->receipt_number,
                'debit' => 0,
                'credit' => (float) $rc->amount,
                'balance' => $balance,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return ['customer' => $customer, 'rows' => $rows, 'balance' => $balance];
    }
}
