<?php

use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$inv = PurchaseInvoice::query()->with('lines')->where('invoice_number', 'PI-2026-00021')->first();
if (! $inv) {
    echo "NOT_FOUND\n";
    exit(0);
}

echo "id={$inv->id}\n";
echo "status={$inv->status}\n";
echo "currency={$inv->currency} rate={$inv->exchange_rate}\n";
echo "subtotal={$inv->subtotal}\n";
echo "discount={$inv->discount_amount}\n";
echo "tax={$inv->tax_amount}\n";
echo "customs={$inv->customs_amount}\n";
echo "transport={$inv->transport_fees}\n";
echo "fines={$inv->fines_amount}\n";
echo "other={$inv->other_fees}\n";
echo 'extras='.$inv->extrasTotal()."\n";
echo "total={$inv->total}\n";
echo "paid={$inv->paid_amount}\n";

$lineSum = 0.0;
foreach ($inv->lines as $l) {
    $lt = round((float) $l->quantity * (float) $l->unit_cost, 2);
    $lineSum += $lt;
    echo "LINE id={$l->id} product={$l->product_id} qty={$l->quantity} cost={$l->unit_cost} tax_rate={$l->tax_rate} line_total={$l->line_total} calc={$lt}\n";
}
echo "line_sum={$lineSum}\n";
echo 'return_preview_lines_plus_extras='.round($lineSum + $inv->extrasTotal(), 2)."\n";
echo 'invoice_total='.round((float) $inv->total, 2)."\n";
echo 'diff='.round(($lineSum + $inv->extrasTotal()) - (float) $inv->total, 2)."\n";

$rets = PurchaseReturn::query()->where('purchase_invoice_id', $inv->id)->orderBy('id')->get();
foreach ($rets as $r) {
    echo "RET {$r->return_number} status={$r->status} total={$r->total} customs={$r->customs_amount} transport={$r->transport_fees} fines={$r->fines_amount} other={$r->other_fees}\n";
}
