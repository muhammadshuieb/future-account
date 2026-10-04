<?php

/**
 * Link unallocated RC-2026-00023 (485) to SI-2026-00021 and sync paid_amount.
 * GL already posted on the receipt — only invoice allocation fields change.
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Receipt;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

$inv = SalesInvoice::query()->where('invoice_number', 'SI-2026-00021')->first();
$rc = Receipt::query()->where('receipt_number', 'RC-2026-00023')->first();

if (! $inv || ! $rc) {
    echo "MISSING inv=".($inv?->id ?? 'null')." rc=".($rc?->id ?? 'null')."\n";
    exit(1);
}

echo "BEFORE inv paid={$inv->paid_amount} rc.inv=".($rc->sales_invoice_id ?? 'null')."\n";

if ($rc->status !== 'posted') {
    echo "RECEIPT_NOT_POSTED\n";
    exit(1);
}

if ((int) $rc->customer_id !== (int) $inv->customer_id) {
    echo "CUSTOMER_MISMATCH\n";
    exit(1);
}

DB::transaction(function () use ($inv, $rc) {
    $inv = SalesInvoice::query()->lockForUpdate()->findOrFail($inv->id);
    $rc = Receipt::query()->lockForUpdate()->findOrFail($rc->id);

    if ($rc->sales_invoice_id && (int) $rc->sales_invoice_id !== (int) $inv->id) {
        throw new RuntimeException('Receipt already linked to another invoice: '.$rc->sales_invoice_id);
    }

    $amount = round((float) $rc->amount, 2);
    $remaining = round((float) $inv->total - (float) $inv->paid_amount, 2);

    if (! $rc->sales_invoice_id) {
        if ($amount > $remaining + 0.001) {
            throw new RuntimeException("Amount {$amount} exceeds remaining {$remaining}");
        }
        $rc->update(['sales_invoice_id' => $inv->id]);
        $inv->increment('paid_amount', $amount);
    } else {
        // Already linked — ensure paid_amount reflects linked posted receipts.
        $sum = (float) Receipt::query()
            ->where('sales_invoice_id', $inv->id)
            ->where('status', 'posted')
            ->sum('amount');
        $inv->update(['paid_amount' => round($sum, 2)]);
    }
});

$inv->refresh();
$rc->refresh();
$rem = round((float) $inv->total - (float) $inv->paid_amount, 2);
$bal = app(\App\Services\SalesService::class)->customerBalance($inv->customer);

echo "AFTER inv paid={$inv->paid_amount} remaining={$rem} rc.inv={$rc->sales_invoice_id}\n";
echo "customerBalance={$bal}\n";
