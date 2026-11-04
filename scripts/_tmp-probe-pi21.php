<?php

use App\Models\PurchaseInvoice;
use App\Services\PurchaseService;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$inv = PurchaseInvoice::query()->with('lines')->where('invoice_number', 'PI-2026-00021')->first();
if (! $inv) {
    echo "NOT_FOUND\n";
    exit(0);
}

$svc = app(PurchaseService::class);
$ref = new ReflectionClass($svc);
$method = $ref->getMethod('effectiveInvoiceLineUnitCost');
$method->setAccessible(true);

$lineSumStored = 0.0;
$lineSumEffective = 0.0;
$mismatches = 0;
foreach ($inv->lines as $l) {
    $storedCalc = round((float) $l->quantity * (float) $l->unit_cost, 2);
    $effective = (float) $method->invoke($svc, $l);
    $effCalc = round((float) $l->quantity * $effective, 2);
    $lineSumStored += $storedCalc;
    $lineSumEffective += $effCalc;
    if (abs($storedCalc - (float) $l->line_total) > 0.001) {
        $mismatches++;
        echo "MISMATCH id={$l->id} qty={$l->quantity} unit={$l->unit_cost} line_total={$l->line_total} stored_calc={$storedCalc} effective={$effective} eff_calc={$effCalc}\n";
    }
}

$extras = $inv->extrasTotal();
echo "subtotal={$inv->subtotal}\n";
echo "discount={$inv->discount_amount}\n";
echo "extras={$extras}\n";
echo "invoice_total={$inv->total}\n";
echo "mismatches={$mismatches}\n";
echo "return_from_stored_unit=".round($lineSumStored + $extras, 2)."\n";
echo "return_from_effective=".round($lineSumEffective + $extras, 2)."\n";
echo 'match_invoice='.(abs(round($lineSumEffective + $extras, 2) - (float) $inv->total) < 0.02 ? 'YES' : 'NO')."\n";
