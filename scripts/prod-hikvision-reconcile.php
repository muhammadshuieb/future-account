<?php

/**
 * Prod probe: reconcile HIKVISION purchase return 2430 vs invoices.
 * Run inside backend container from /var/www/html:
 *   php /var/www/html/../scripts/...  (or copy into html)
 */

// Copied into container at /var/www/html/prod-hikvision-reconcile.php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ret = \App\Models\PurchaseReturn::query()
    ->with(['supplier', 'allocations.invoice', 'invoice'])
    ->where('status', 'posted')
    ->where(function ($q) {
        $q->where('total', 2430)->orWhere('return_number', 'like', '%00001%');
    })
    ->orderBy('id')
    ->first();

if (! $ret) {
    echo "RETURN_NOT_FOUND\n";
    exit(1);
}

$s = $ret->supplier;
echo "=== RETURN ===\n";
echo "number={$ret->return_number} total={$ret->total} applied={$ret->applied_amount} refund={$ret->refund_amount}\n";
echo 'linked='.($ret->invoice->invoice_number ?? 'null')."\n";
echo "supplier={$s->name} id={$s->id}\n";

echo "=== ALLOCATIONS ===\n";
foreach ($ret->allocations as $a) {
    $i = $a->invoice;
    $rem = $i ? round((float) $i->total - (float) $i->paid_amount, 2) : null;
    echo "alloc={$a->amount} -> ".($i->invoice_number ?? '?').' total='.($i->total ?? '').' paid='.($i->paid_amount ?? '')." rem={$rem}\n";
}

echo "=== PURCHASE INVOICES ===\n";
$invs = \App\Models\PurchaseInvoice::query()
    ->where('supplier_id', $s->id)
    ->where('status', 'posted')
    ->orderBy('id')
    ->get();
$sumT = 0.0;
$sumP = 0.0;
$sumR = 0.0;
foreach ($invs as $i) {
    $rem = round((float) $i->total - (float) $i->paid_amount, 2);
    $sumT += (float) $i->total;
    $sumP += (float) $i->paid_amount;
    $sumR += $rem;
    echo "{$i->invoice_number}\ttype={$i->payment_type}\ttotal={$i->total}\tpaid={$i->paid_amount}\trem={$rem}\n";
}
echo 'COUNT='.$invs->count().' SUM_TOTAL='.round($sumT, 2).' SUM_PAID='.round($sumP, 2).' SUM_REMAINING='.round($sumR, 2)."\n";

echo "=== PAYMENTS ===\n";
$pays = \App\Models\SupplierPayment::query()
    ->where('supplier_id', $s->id)
    ->where('status', 'posted')
    ->orderBy('id')
    ->get();
$sumPay = 0.0;
foreach ($pays as $p) {
    $sumPay += (float) $p->amount;
    echo "{$p->payment_number}\tamount={$p->amount}\tinv=".($p->purchase_invoice_id ?? 'null')."\n";
}
echo 'SUM_PAYMENTS='.round($sumPay, 2)."\n";

$bal = app(\App\Services\PurchaseService::class)->supplierBalance($s);
$st = app(\App\Services\PurchaseService::class)->supplierStatement($s);
echo "=== RECONCILE ===\n";
echo "supplierBalance={$bal}\n";
echo 'statement_closing='.$st['closing_balance']."\n";
echo 'invoices_total_minus_payments_minus_return='.round($sumT - $sumPay - (float) $ret->total, 2)."\n";
echo 'diff_balance_vs_remainings='.round((float) $bal - $sumR, 2)."\n";
