<?php

/**
 * Backfill cash_boxes.branch_id / banks.branch_id from posted document usage.
 *
 * Rule: if every posted sales/purchase invoice that references the box/bank
 * shares a single non-null branch_id, assign that branch. Otherwise leave null
 * (visible only when dashboard branch filter is "all branches").
 *
 * Usage (inside backend container):
 *   php /tmp/prod-backfill-cash-box-branches.php
 *   php /tmp/prod-backfill-cash-box-branches.php --apply
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Bank;
use App\Models\CashBox;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv ?? [], true);

function dominantBranchId(string $table, string $fkColumn, int $id): ?int
{
    $rows = DB::table($table)
        ->select('branch_id', DB::raw('count(*) as c'))
        ->where($fkColumn, $id)
        ->where('status', 'posted')
        ->whereNotNull('branch_id')
        ->groupBy('branch_id')
        ->orderByDesc('c')
        ->get();

    if ($rows->isEmpty()) {
        return null;
    }

    $ids = $rows->pluck('branch_id')->unique()->values();
    if ($ids->count() !== 1) {
        return null;
    }

    return (int) $ids->first();
}

function resolveCashBoxBranch(int $boxId): ?int
{
    $fromSales = dominantBranchId('sales_invoices', 'cash_box_id', $boxId);
    $fromPurchases = dominantBranchId('purchase_invoices', 'cash_box_id', $boxId);

    $candidates = array_values(array_unique(array_filter([$fromSales, $fromPurchases])));
    if (count($candidates) === 1) {
        return $candidates[0];
    }

    // Prefer sales evidence when both agree or only one side has data.
    if ($fromSales !== null && $fromPurchases === null) {
        return $fromSales;
    }
    if ($fromPurchases !== null && $fromSales === null) {
        return $fromPurchases;
    }

    return null;
}

$report = ['apply' => $apply, 'cash_boxes' => [], 'banks' => []];

foreach (CashBox::query()->orderBy('id')->get() as $box) {
    $suggested = $box->branch_id ? (int) $box->branch_id : resolveCashBoxBranch((int) $box->id);
    $row = [
        'id' => $box->id,
        'code' => $box->code,
        'name' => $box->name,
        'current_branch_id' => $box->branch_id,
        'suggested_branch_id' => $suggested,
        'action' => 'none',
    ];

    if ($box->branch_id === null && $suggested !== null) {
        $row['action'] = $apply ? 'updated' : 'would_update';
        if ($apply) {
            $box->update(['branch_id' => $suggested]);
        }
    } elseif ($box->branch_id === null) {
        $row['action'] = 'leave_unassigned';
    }

    $report['cash_boxes'][] = $row;
}

foreach (Bank::query()->orderBy('id')->get() as $bank) {
    $report['banks'][] = [
        'id' => $bank->id,
        'code' => $bank->code,
        'name' => $bank->name,
        'current_branch_id' => $bank->branch_id,
        'suggested_branch_id' => $bank->branch_id,
        'action' => $bank->branch_id === null ? 'leave_unassigned' : 'none',
    ];
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
