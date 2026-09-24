<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\StockMovement;
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
            [$subtotal, $tax, $linesTotal, $normalized] = $this->normalizeLines($lines);
            $extras = $this->normalizeExtras($data);
            $extrasSum = round(
                $extras['customs_amount'] + $extras['transport_fees'] + $extras['fines_amount'] + $extras['other_fees'],
                2
            );
            $discount = $this->normalizeDiscountAmount($data['discount_amount'] ?? 0, $subtotal);
            // Landed cost: line subtotal + tax (if enabled) + optional extras − invoice discount (حسم).
            $total = round($linesTotal + $extrasSum - $discount, 2);

            $fx = $this->currencies->resolveDocumentFx(
                $total,
                $data['currency'] ?? null,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : null,
                $data['invoice_date'] ?? null,
            );

            [$paymentType, $intendedPaid, $cashBoxId] = $this->normalizePaymentTerms($data, $total, $fx['currency']);

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
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'customs_amount' => $extras['customs_amount'],
                'transport_fees' => $extras['transport_fees'],
                'fines_amount' => $extras['fines_amount'],
                'other_fees' => $extras['other_fees'],
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

    public function postInvoice(
        PurchaseInvoice $invoice,
        User $user,
        ?float $intendedPaidOverride = null,
        bool $applyStock = true,
    ): PurchaseInvoice {
        if ($invoice->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['فاتورة المشتريات مرحّلة مسبقاً.']]);
        }

        return DB::transaction(function () use ($invoice, $user, $intendedPaidOverride, $applyStock) {
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

            // Unpaid credit purchases must never create a cash payment.
            if ($paymentType === 'credit') {
                $intendedPaid = 0.0;
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
            // Extras (customs/transport/fines/other) are capitalized into inventory/AP — not expensed.
            // Inventory debit = base total − recoverable VAT so FX rounding stays on inventory.
            $baseInventory = round($baseTotal - $baseTax, 2);

            $glLines = [
                ['account_id' => $inventory->id, 'debit' => $baseInventory, 'credit' => 0],
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

            $extrasDoc = $invoice->extrasTotal();
            $discountDoc = round((float) ($invoice->discount_amount ?? 0), 2);
            // Net adjustment capitalized into unit cost: extras increase cost, discount reduces it.
            $netAdjDoc = round($extrasDoc - $discountDoc, 2);
            $lineWeights = [];
            $weightSum = 0.0;
            foreach ($invoice->lines as $line) {
                $lineSub = round((float) $line->quantity * (float) $line->unit_cost, 2);
                $lineWeights[$line->id] = $lineSub;
                $weightSum += $lineSub;
            }

            $allocatedAdj = 0.0;
            $lineIds = $invoice->lines->pluck('id')->all();
            $lastLineId = $lineIds === [] ? null : $lineIds[array_key_last($lineIds)];

            foreach ($invoice->lines as $line) {
                $product = $line->product;
                $qty = (float) $line->quantity;
                $lineSub = $lineWeights[$line->id] ?? 0.0;

                if (abs($netAdjDoc) > 0.00001 && $qty > 0) {
                    if ($line->id === $lastLineId) {
                        $lineAdj = round($netAdjDoc - $allocatedAdj, 2);
                    } elseif ($weightSum > 0) {
                        $lineAdj = round($netAdjDoc * ($lineSub / $weightSum), 2);
                        $allocatedAdj += $lineAdj;
                    } else {
                        $lineAdj = 0.0;
                    }
                    // Document currency unit cost including proportional landed-cost / discount share.
                    $docUnitCost = round(($lineSub + $lineAdj) / $qty, 4);
                } else {
                    $docUnitCost = (float) $line->unit_cost;
                }

                // Line costs are in the invoice currency; product cost and stock cost are kept in base currency.
                $baseUnitCost = round($docUnitCost * $rate, 4);

                // Financial-only re-post (stock already consumed) updates GL only.
                if (! $applyStock) {
                    continue;
                }

                $product->update([
                    'cost_price' => $this->inventory->movingAverageCost($product, $qty, $baseUnitCost),
                ]);

                $this->inventory->adjustStock(
                    $invoice->warehouse_id,
                    $line->product_id,
                    $qty,
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
                    : $this->cash->resolveDefaultCashBoxId(null, $invoice->currency);

                if (! $cashBoxId) {
                    throw ValidationException::withMessages([
                        'cash_box_id' => ['يجب تحديد صندوق بعملة '.$invoice->currency.' عند الدفع نقداً أو بدفعة جزئية.'],
                    ]);
                }

                $this->cash->assertCashBoxCurrency($cashBoxId, $invoice->currency);

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
     * Safely update a purchase invoice (draft rewrite, or posted reverse → rewrite → re-post).
     *
     * When purchased qty was already sold/moved, stock cannot be reversed. In that case:
     * - Same products/qty/warehouse/batch/serial → financial-only rewrite (GL + payments; stock untouched).
     * - Identity change (qty/product/warehouse/…) → clear Arabic validation error.
     */
    public function updateInvoice(PurchaseInvoice $invoice, array $data, array $lines, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $lines, $user) {
            $wasPosted = $invoice->status === 'posted';
            if (! in_array($invoice->status, ['draft', 'posted'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن تعديل هذه الفاتورة بحالتها الحالية.'],
                ]);
            }

            $applyStock = true;
            if ($wasPosted) {
                $invoice->loadMissing(['lines']);
                $warehouseId = $this->resolveWarehouseId(
                    array_key_exists('warehouse_id', $data)
                        ? (isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null)
                        : ($invoice->warehouse_id ? (int) $invoice->warehouse_id : null)
                );
                $identityChanged = $this->purchaseStockIdentityChanged($invoice, $warehouseId, $lines);
                $canReverseStock = $this->canReversePurchaseStock($invoice);

                if (! $canReverseStock) {
                    if ($identityChanged) {
                        throw ValidationException::withMessages([
                            'quantity' => ['لا يمكن تعديل أصناف/كميات هذه الفاتورة لأن جزءاً من الكمية تم بيعه أو صرفه من المخزن. عدّل السعر أو الحسم أو الملاحظات فقط، أو أعد الكمية للمخزن أولاً.'],
                        ]);
                    }
                    $applyStock = false;
                }

                $this->unpostInvoice($invoice, $user, reverseStock: $applyStock);
                $invoice->refresh();
            }

            [$subtotal, $tax, $linesTotal, $normalized] = $this->normalizeLines($lines);
            $extras = $this->normalizeExtras($data);
            $extrasSum = round(
                $extras['customs_amount'] + $extras['transport_fees'] + $extras['fines_amount'] + $extras['other_fees'],
                2
            );
            $discount = $this->normalizeDiscountAmount($data['discount_amount'] ?? 0, $subtotal);
            $total = round($linesTotal + $extrasSum - $discount, 2);

            $fx = $this->currencies->resolveDocumentFx(
                $total,
                $data['currency'] ?? $invoice->currency,
                isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : (float) $invoice->exchange_rate,
                $data['invoice_date'] ?? $invoice->invoice_date?->toDateString(),
            );

            [$paymentType, $intendedPaid, $cashBoxId] = $this->normalizePaymentTerms($data, $total, $fx['currency']);
            $wantPosted = ($data['status'] ?? ($wasPosted ? 'posted' : 'draft')) === 'posted';

            $warehouseId = $this->resolveWarehouseId(
                array_key_exists('warehouse_id', $data)
                    ? (isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null)
                    : ($invoice->warehouse_id ? (int) $invoice->warehouse_id : null)
            );

            $invoice->update([
                'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                'supplier_id' => $data['supplier_id'] ?? $invoice->supplier_id,
                'warehouse_id' => $warehouseId,
                'cash_box_id' => $cashBoxId,
                'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $invoice->branch_id,
                'payment_type' => $paymentType,
                'currency' => $fx['currency'],
                'exchange_rate' => $fx['exchange_rate'],
                'base_amount' => $fx['base_amount'],
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'customs_amount' => $extras['customs_amount'],
                'transport_fees' => $extras['transport_fees'],
                'fines_amount' => $extras['fines_amount'],
                'other_fees' => $extras['other_fees'],
                'total' => $total,
                'paid_amount' => in_array($paymentType, ['cash', 'partial'], true) ? $intendedPaid : 0,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $invoice->notes,
                'status' => 'draft',
                'journal_entry_id' => null,
                'posted_at' => null,
            ]);

            $invoice->lines()->delete();
            foreach ($normalized as $line) {
                $invoice->lines()->create($line);
            }

            if ($wantPosted) {
                return $this->postInvoice(
                    $invoice->fresh(['lines.product.unit', 'supplier']),
                    $user,
                    $intendedPaid,
                    applyStock: $applyStock,
                );
            }

            $this->audit->log($user, 'purchase_invoice.updated', $invoice);

            return $invoice->fresh(['lines.product.unit', 'supplier', 'warehouse', 'cashBox', 'attachments']);
        });
    }

    /**
     * True when warehouse or line identity (product/qty/batch/serial) differs from the posted invoice.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function purchaseStockIdentityChanged(PurchaseInvoice $invoice, int $warehouseId, array $lines): bool
    {
        if ((int) $invoice->warehouse_id !== $warehouseId) {
            return true;
        }

        $normalize = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $out[] = sprintf(
                    '%d|%s|%s|%s',
                    (int) ($row['product_id'] ?? 0),
                    rtrim(rtrim(number_format((float) ($row['quantity'] ?? 0), 3, '.', ''), '0'), '.') ?: '0',
                    (string) ($row['batch_no'] ?? ''),
                    (string) ($row['serial_no'] ?? ''),
                );
            }
            sort($out);

            return $out;
        };

        $existing = $invoice->lines->map(fn ($line) => [
            'product_id' => $line->product_id,
            'quantity' => $line->quantity,
            'batch_no' => $line->batch_no,
            'serial_no' => $line->serial_no,
        ])->all();

        return $normalize($existing) !== $normalize($lines);
    }

    /** Whether current posted stock movements can still be reversed from on-hand qty. */
    protected function canReversePurchaseStock(PurchaseInvoice $invoice): bool
    {
        $movements = $this->activePurchaseStockMovements($invoice);
        foreach ($movements as $movement) {
            $qty = (float) $movement->quantity;
            if ($qty <= 0.0001) {
                continue;
            }
            $product = Product::query()->find($movement->product_id);
            if (! $product) {
                return false;
            }
            $available = $this->inventory->availableQty(
                (int) $movement->warehouse_id,
                (int) $movement->product_id,
                $product->track_batch ? ($movement->batch_no ?: null) : null,
                $product,
            );
            if ($available + 0.0001 < $qty) {
                return false;
            }
        }

        return true;
    }

    /** Stock movements belonging to the current posting (excludes prior edit reverse rows). */
    protected function activePurchaseStockMovements(PurchaseInvoice $invoice)
    {
        $query = StockMovement::query()
            ->where('reference_type', $invoice::class)
            ->where('reference_id', $invoice->id);

        if ($invoice->journal_entry_id) {
            $query->where('journal_entry_id', $invoice->journal_entry_id);
        } else {
            // Legacy rows without JE link: ignore explicit reverse movements from earlier edits.
            $query->where(function ($q) {
                $q->whereNull('notes')->orWhere('notes', 'not like', 'عكس ترحيل%');
            });
        }

        return $query->orderByDesc('id')->get();
    }

    protected function unpostInvoice(PurchaseInvoice $invoice, User $user, bool $reverseStock = true): void
    {
        if ($invoice->status !== 'posted') {
            return;
        }

        $linkedReturns = PurchaseReturn::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->whereIn('status', ['draft', 'posted'])
            ->count();
        if ($linkedReturns > 0) {
            throw ValidationException::withMessages([
                'status' => ['لا يمكن تعديل فاتورة مرتبطة بمرتجع. احذف/ألغِ المرتجع أولاً أو أنشئ فاتورة جديدة.'],
            ]);
        }

        $invoice->load(['payments', 'lines.product']);

        foreach ($invoice->payments()->where('status', 'posted')->lockForUpdate()->get() as $payment) {
            if ($payment->journal_entry_id) {
                $entry = JournalEntry::query()->find($payment->journal_entry_id);
                if ($entry && $entry->status === 'posted') {
                    $this->journals->void($entry);
                }
            }
            $payment->update(['status' => 'void', 'journal_entry_id' => null]);
        }

        if ($reverseStock) {
            $movements = $this->activePurchaseStockMovements($invoice);

            foreach ($movements as $movement) {
                $qty = (float) $movement->quantity;
                if (abs($qty) < 0.0001) {
                    continue;
                }

                $product = Product::query()->find($movement->product_id);
                if ($product && $qty > 0) {
                    $existingQty = round((float) \App\Models\StockLevel::query()
                        ->where('product_id', $product->id)
                        ->where('quantity', '>', 0)
                        ->sum('quantity'), 3);
                    $product->update([
                        'cost_price' => $this->inventory->reverseMovingAverageCost(
                            $product,
                            $existingQty,
                            $qty,
                            (float) $movement->unit_cost
                        ),
                    ]);
                }

                // Original purchase stores positive qty (in); reverse deducts stock (out).
                $this->inventory->adjustStock(
                    (int) $movement->warehouse_id,
                    (int) $movement->product_id,
                    -$qty,
                    $qty > 0 ? 'out' : 'in',
                    $user,
                    [
                        'movement_date' => now()->toDateString(),
                        'unit_cost' => $movement->unit_cost,
                        'batch_no' => $movement->batch_no,
                        'serial_no' => $movement->serial_no,
                        'reference_type' => $invoice::class,
                        'reference_id' => $invoice->id,
                        'notes' => 'عكس ترحيل فاتورة مشتريات '.$invoice->invoice_number,
                    ]
                );
            }
        }

        if ($invoice->journal_entry_id) {
            $entry = JournalEntry::query()->find($invoice->journal_entry_id);
            if ($entry && $entry->status === 'posted') {
                $this->journals->void($entry);
            }
        }

        $invoice->update([
            'status' => 'draft',
            'journal_entry_id' => null,
            'posted_at' => null,
            'paid_amount' => 0,
        ]);

        $this->audit->log($user, 'purchase_invoice.unposted', $invoice);
    }

    /**
     * @return array{0: string, 1: float, 2: ?int}
     */
    protected function normalizePaymentTerms(array $data, float $total, ?string $currency = null): array
    {
        $paymentType = strtolower((string) ($data['payment_type'] ?? 'credit'));
        if (! in_array($paymentType, ['cash', 'credit', 'partial'], true)) {
            throw ValidationException::withMessages(['payment_type' => ['نوع الدفع غير صالح.']]);
        }

        $cashBoxId = isset($data['cash_box_id']) && $data['cash_box_id'] !== '' && $data['cash_box_id'] !== null
            ? (int) $data['cash_box_id']
            : null;

        // Credit (آجل) never touches cash: ignore any cash_box_id / paid_amount from the client.
        if ($paymentType === 'credit') {
            return ['credit', 0.0, null];
        }

        $docCurrency = strtoupper((string) ($currency ?: ($data['currency'] ?? $this->currencies->baseCurrency())));

        if (in_array($paymentType, ['cash', 'partial'], true) && ! $cashBoxId) {
            $cashBoxId = $this->cash->resolveDefaultCashBoxId(null, $docCurrency);
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
                'cash_box_id' => ['يجب تحديد صندوق بعملة '.$docCurrency.' عند الدفع نقداً أو بدفعة جزئية.'],
            ]);
        }

        if ($cashBoxId) {
            $this->cash->assertCashBoxCurrency($cashBoxId, $docCurrency);
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
            $bankId = isset($data['bank_id']) && $data['bank_id'] !== '' && $data['bank_id'] !== null
                ? (int) $data['bank_id']
                : null;

            // Cash stays in the same-currency box — never auto-route into the base USD box.
            if ($method === 'cash' && ! $bankId) {
                if (! $cashBoxId) {
                    $cashBoxId = $this->cash->resolveDefaultCashBoxId(null, $fx['currency']);
                }
                if (! $cashBoxId) {
                    throw ValidationException::withMessages([
                        'cash_box_id' => ['يجب تحديد صندوق بعملة '.$fx['currency'].'. المبلغ يبقى في صندوق عملته ولا يُحوَّل تلقائياً.'],
                    ]);
                }
            }

            $this->cash->assertCashBoxCurrency($cashBoxId, $fx['currency']);
            $this->cash->assertBankCurrency($bankId, $fx['currency']);

            $payment = SupplierPayment::query()->create([
                'payment_number' => $this->nextNumber('SP'),
                'payment_date' => $data['payment_date'],
                'supplier_id' => $data['supplier_id'],
                'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
                'cash_box_id' => $cashBoxId,
                'bank_id' => $bankId,
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

    /**
     * Pay remaining unpaid balance on a posted purchase invoice (credit/partial).
     * Creates a posted supplier payment allocated to the invoice.
     */
    public function payRemaining(PurchaseInvoice $invoice, User $user, array $data = []): SupplierPayment
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

        return $this->createPayment([
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'supplier_id' => $invoice->supplier_id,
            'purchase_invoice_id' => $invoice->id,
            'cash_box_id' => $data['cash_box_id'] ?? $invoice->cash_box_id,
            'bank_id' => $data['bank_id'] ?? null,
            'method' => $data['method'] ?? 'cash',
            'amount' => $amount,
            'currency' => $data['currency'] ?? $invoice->currency,
            'exchange_rate' => isset($data['exchange_rate'])
                ? (float) $data['exchange_rate']
                : ($invoice->exchange_rate ? (float) $invoice->exchange_rate : null),
            'base_amount' => $data['base_amount'] ?? null,
            'notes' => $data['notes'] ?? ('دفع باقي فاتورة شراء '.$invoice->invoice_number),
            'status' => 'posted',
        ], $user);
    }

    public function postPayment(SupplierPayment $payment, User $user): SupplierPayment
    {
        if ($payment->status === 'posted') {
            throw ValidationException::withMessages(['status' => ['سند الصرف مرحّل مسبقاً.']]);
        }

        return DB::transaction(function () use ($payment, $user) {
            $payment->load('supplier');
            $cashCode = $payment->method === 'bank' ? '1102' : '1101';

            $this->cash->assertCashBoxCurrency(
                $payment->cash_box_id ? (int) $payment->cash_box_id : null,
                $payment->currency
            );
            $this->cash->assertBankCurrency(
                $payment->bank_id ? (int) $payment->bank_id : null,
                $payment->currency
            );

            if ($payment->method === 'bank' && $payment->bank_id) {
                $bank = \App\Models\Bank::query()->findOrFail($payment->bank_id);
                $creditAccount = $bank->account_id
                    ? Account::query()->findOrFail($bank->account_id)
                    : Account::query()->where('code', '1102')->firstOrFail();
            } elseif ($payment->cash_box_id) {
                $box = \App\Models\CashBox::query()->findOrFail($payment->cash_box_id);
                $creditAccount = $this->cash->resolveCashBoxAccount($box);
            } else {
                $creditAccount = Account::query()->where('code', $cashCode)->firstOrFail();
            }

            $ap = $payment->supplier->account_id
                ? Account::query()->findOrFail($payment->supplier->account_id)
                : Account::query()->where('code', '2101')->firstOrFail();

            $baseAmount = (float) ($payment->base_amount ?: $payment->amount);

            if ($payment->purchase_invoice_id) {
                $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($payment->purchase_invoice_id);
                if ((int) $invoice->supplier_id !== (int) $payment->supplier_id) {
                    throw ValidationException::withMessages([
                        'purchase_invoice_id' => ['الفاتورة لا تخص هذا المورد.'],
                    ]);
                }
                if ($invoice->status !== 'posted') {
                    throw ValidationException::withMessages([
                        'purchase_invoice_id' => ['لا يمكن الدفع على فاتورة غير مرحّلة.'],
                    ]);
                }
                $applied = $this->paymentAmountInInvoiceCurrency($payment, $invoice);
                $remaining = round((float) $invoice->total - (float) $invoice->paid_amount, 2);
                if ($applied > $remaining + 0.001) {
                    throw ValidationException::withMessages([
                        'amount' => ['المبلغ يتجاوز المتبقي على الفاتورة ('.$remaining.').'],
                    ]);
                }
            }

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
     * Optional purchase landed-cost extras (document currency).
     *
     * @return array{customs_amount: float, transport_fees: float, fines_amount: float, other_fees: float}
     */
    protected function normalizeExtras(array $data): array
    {
        $fields = ['customs_amount', 'transport_fees', 'fines_amount', 'other_fees'];
        $out = [];
        foreach ($fields as $field) {
            $value = round((float) ($data[$field] ?? 0), 2);
            if ($value < 0) {
                throw ValidationException::withMessages([$field => ['يجب أن يكون المبلغ صفراً أو أكبر.']]);
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /** Optional invoice-level discount (حسم); must be >= 0 and <= subtotal. */
    protected function normalizeDiscountAmount(mixed $raw, float $subtotal): float
    {
        $discount = round(max(0, (float) $raw), 2);

        if ($discount > round($subtotal, 2) + 0.00001) {
            throw ValidationException::withMessages([
                'discount_amount' => ['الحسم لا يجوز أن يتجاوز مجموع البنود.'],
            ]);
        }

        return $discount;
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
        foreach ($supplier->invoices()->where('status', 'posted')->with(['lines.product'])->get() as $inv) {
            $events[] = [
                'date' => $inv->invoice_date->toDateString(),
                'type' => 'invoice',
                'number' => $inv->invoice_number,
                'document_id' => (int) $inv->id,
                'currency' => $inv->currency,
                'document_amount' => (float) $inv->total,
                'notes' => $inv->notes,
                'debit' => 0.0,
                'credit' => $this->baseValue($inv->base_amount, $inv->total, $inv->exchange_rate),
                'invoice' => $this->statementInvoiceDetail($inv),
            ];
        }

        foreach ($supplier->payments()->where('status', 'posted')->get() as $pay) {
            $events[] = [
                'date' => $pay->payment_date->toDateString(),
                'type' => 'payment',
                'number' => $pay->payment_number,
                'document_id' => (int) $pay->id,
                'currency' => $pay->currency,
                'document_amount' => (float) $pay->amount,
                'notes' => $pay->notes,
                'debit' => $this->baseValue($pay->base_amount, $pay->amount, $pay->exchange_rate),
                'credit' => 0.0,
                'invoice' => null,
            ];
        }

        foreach (PurchaseReturn::query()->where('supplier_id', $supplier->id)->where('status', 'posted')->get() as $ret) {
            $events[] = [
                'date' => $ret->return_date->toDateString(),
                'type' => 'return',
                'number' => $ret->return_number,
                'document_id' => (int) $ret->id,
                'currency' => $ret->currency,
                'document_amount' => (float) $ret->total,
                'notes' => null,
                'debit' => $this->baseValue($ret->base_amount, $ret->total, $ret->exchange_rate),
                'credit' => 0.0,
                'invoice' => null,
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
                'document_id' => $event['document_id'],
                'currency' => $event['currency'],
                'document_amount' => $event['document_amount'],
                'notes' => $event['notes'],
                'debit' => $event['debit'],
                'credit' => $event['credit'],
                'balance' => round($balance, 2),
                'invoice' => $event['invoice'],
            ];
        }

        if (! $from) {
            $openingBalance = 0.0;
        }

        $closingBalance = $rows === [] ? $openingBalance : (float) $rows[array_key_last($rows)]['balance'];
        $totalDebit = round(collect($rows)->sum('debit'), 2);
        $totalCredit = round(collect($rows)->sum('credit'), 2);

        return [
            'supplier' => $supplier,
            'from' => $from,
            'to' => $to,
            'currency' => $this->currencies->baseCurrency(),
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($closingBalance, 2),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
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

    /**
     * Full purchase-invoice payload for account-statement rows (avoids N+1 on the client).
     *
     * @return array<string, mixed>
     */
    protected function statementInvoiceDetail(PurchaseInvoice $invoice): array
    {
        return [
            'payment_type' => $invoice->payment_type,
            'subtotal' => (float) $invoice->subtotal,
            'discount_amount' => (float) $invoice->discount_amount,
            'tax_amount' => (float) $invoice->tax_amount,
            'total' => (float) $invoice->total,
            'paid_amount' => (float) $invoice->paid_amount,
            'currency' => $invoice->currency,
            'notes' => $invoice->notes,
            'lines' => $invoice->lines->map(static function (PurchaseInvoiceLine $line): array {
                $product = $line->product;

                return [
                    'quantity' => (float) $line->quantity,
                    'unit_cost' => (float) $line->unit_cost,
                    'line_total' => (float) $line->line_total,
                    'tax_rate' => (float) $line->tax_rate,
                    'batch_no' => $line->batch_no,
                    'serial_no' => $line->serial_no,
                    'product' => $product ? [
                        'name' => $product->name,
                        'sku' => $product->sku,
                        'brand' => $product->brand,
                        'model' => $product->model,
                    ] : null,
                ];
            })->values()->all(),
        ];
    }
}
