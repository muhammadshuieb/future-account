<?php

/**
 * Find customers where statement balance != sum of invoice remainings
 * (same mismatch class as SI-21 / unallocated receipts).
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sales = app(\App\Services\SalesService::class);
$mismatches = [];
$checked = 0;

foreach (\App\Models\Customer::query()->orderBy('id')->get() as $c) {
    $checked++;
    $invs = \App\Models\SalesInvoice::query()
        ->where('customer_id', $c->id)
        ->where('status', 'posted')
        ->get(['id', 'invoice_number', 'total', 'paid_amount']);
    $sumRem = 0.0;
    $open = [];
    foreach ($invs as $i) {
        $rem = round((float) $i->total - (float) $i->paid_amount, 2);
        $sumRem += $rem;
        if ($rem > 0.001) {
            $open[] = "{$i->invoice_number}:{$rem}";
        }
    }
    $bal = round((float) $sales->customerBalance($c), 2);
    $diff = round($bal - $sumRem, 2);
    if (abs($diff) > 0.02) {
        $mismatches[] = [
            'id' => $c->id,
            'name' => $c->name,
            'balance' => $bal,
            'remainings' => $sumRem,
            'diff' => $diff,
            'open' => implode(', ', $open),
        ];
    }
}

echo "CHECKED={$checked}\n";
echo 'MISMATCHES='.count($mismatches)."\n";
foreach ($mismatches as $m) {
    echo "customer#{$m['id']} {$m['name']} balance={$m['balance']} remainings={$m['remainings']} diff={$m['diff']} open=[{$m['open']}]\n";
}

echo "=== UNALLOCATED RECEIPTS ===\n";
$rcs = \App\Models\Receipt::query()
    ->with('customer')
    ->where('status', 'posted')
    ->whereRaw('(amount - COALESCE(applied_amount, 0)) > 0.001')
    ->orderBy('id')
    ->get();
foreach ($rcs as $r) {
    echo "{$r->receipt_number} customer={$r->customer?->name} amount={$r->amount} applied={$r->applied_amount} inv=".($r->sales_invoice_id ?? 'null')."\n";
}
echo 'UNALLOC_COUNT='.$rcs->count()."\n";
