<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$inv = \App\Models\SalesInvoice::query()
    ->with(['customer', 'receipts' => fn ($q) => $q->orderBy('id')])
    ->where('invoice_number', 'SI-2026-00021')
    ->first();

if (! $inv) {
    echo "INVOICE_NOT_FOUND\n";
    exit(1);
}

$c = $inv->customer;
$rem = round((float) $inv->total - (float) $inv->paid_amount, 2);

echo "=== INVOICE ===\n";
echo "number={$inv->invoice_number} id={$inv->id}\n";
echo "status={$inv->status} payment_type={$inv->payment_type}\n";
echo "total={$inv->total} paid={$inv->paid_amount} remaining={$rem}\n";
echo "currency={$inv->currency} date={$inv->invoice_date}\n";
echo "customer={$c->name} id={$c->id}\n";

echo "=== RECEIPTS LINKED TO THIS INVOICE ===\n";
$linked = \App\Models\Receipt::query()
    ->where('sales_invoice_id', $inv->id)
    ->orderBy('id')
    ->get();
$sumLinked = 0.0;
foreach ($linked as $r) {
    $sumLinked += (float) $r->amount;
    echo "{$r->receipt_number}\tstatus={$r->status}\tamount={$r->amount}\tcurrency={$r->currency}\tdate={$r->receipt_date}\n";
}
echo 'SUM_LINKED='.round($sumLinked, 2)."\n";

echo "=== ALL RECEIPTS FOR CUSTOMER ===\n";
$allRc = \App\Models\Receipt::query()
    ->where('customer_id', $c->id)
    ->where('status', 'posted')
    ->orderBy('id')
    ->get();
$sumRc = 0.0;
$sumRcUnalloc = 0.0;
foreach ($allRc as $r) {
    $sumRc += (float) $r->amount;
    if (! $r->sales_invoice_id) {
        $sumRcUnalloc += (float) $r->amount;
    }
    echo "{$r->receipt_number}\tamount={$r->amount}\tinv=".($r->sales_invoice_id ?? 'UNALLOCATED')."\tcurrency={$r->currency}\n";
}
echo 'SUM_RECEIPTS='.round($sumRc, 2)." UNALLOCATED=".round($sumRcUnalloc, 2)."\n";

echo "=== ALL SALES INVOICES FOR CUSTOMER ===\n";
$invs = \App\Models\SalesInvoice::query()
    ->where('customer_id', $c->id)
    ->where('status', 'posted')
    ->orderBy('id')
    ->get();
$sumT = 0.0;
$sumP = 0.0;
$sumR = 0.0;
foreach ($invs as $i) {
    $r = round((float) $i->total - (float) $i->paid_amount, 2);
    $sumT += (float) $i->total;
    $sumP += (float) $i->paid_amount;
    $sumR += $r;
    echo "{$i->invoice_number}\ttype={$i->payment_type}\ttotal={$i->total}\tpaid={$i->paid_amount}\trem={$r}\n";
}
echo 'COUNT='.$invs->count().' SUM_TOTAL='.round($sumT, 2).' SUM_PAID='.round($sumP, 2).' SUM_REMAINING='.round($sumR, 2)."\n";

echo "=== RETURNS ===\n";
$rets = \App\Models\SalesReturn::query()
    ->where('customer_id', $c->id)
    ->where('status', 'posted')
    ->orderBy('id')
    ->get();
$sumRet = 0.0;
foreach ($rets as $ret) {
    $sumRet += (float) $ret->total;
    echo "{$ret->return_number}\ttotal={$ret->total}\tapplied={$ret->applied_amount}\trefund={$ret->refund_amount}\tinv=".($ret->sales_invoice_id ?? 'null')."\n";
    foreach (\App\Models\SalesReturnAllocation::query()->where('sales_return_id', $ret->id)->get() as $a) {
        $ai = \App\Models\SalesInvoice::find($a->sales_invoice_id);
        echo "  alloc={$a->amount} -> ".($ai->invoice_number ?? $a->sales_invoice_id)."\n";
    }
}
echo 'SUM_RETURNS='.round($sumRet, 2)."\n";

$bal = app(\App\Services\SalesService::class)->customerBalance($c);
$st = app(\App\Services\SalesService::class)->customerStatement($c);
echo "=== RECONCILE ===\n";
echo "customerBalance={$bal}\n";
echo 'statement_closing='.$st['closing_balance']."\n";
echo 'invoice_remainings_sum='.round($sumR, 2)."\n";
echo 'diff_balance_vs_remainings='.round((float) $bal - $sumR, 2)."\n";
echo 'formula_invoices_minus_receipts_minus_returns='.round($sumT - $sumRc - $sumRet, 2)."\n";
