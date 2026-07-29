<?php

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Bank;
use App\Models\CashBox;
use App\Models\Setting;
use App\Services\CashService;

$cash = app(CashService::class);
$base = strtoupper((string) Setting::getValue('currency', 'USD'));

$boxes = CashBox::query()->orderBy('code')->get()->map(function (CashBox $box) use ($cash) {
    return [
        'id' => $box->id,
        'code' => $box->code,
        'name' => $box->name,
        'currency' => strtoupper((string) ($box->currency ?: 'USD')),
        'branch_id' => $box->branch_id,
        'account_id' => $box->account_id,
        'opening_balance' => (float) $box->opening_balance,
        'is_active' => (bool) $box->is_active,
        'balance' => $cash->cashBoxCurrencyBalance($box),
    ];
})->values();

$banks = Bank::query()->orderBy('code')->get()->map(function (Bank $bank) use ($cash) {
    return [
        'id' => $bank->id,
        'code' => $bank->code,
        'name' => $bank->name,
        'currency' => strtoupper((string) ($bank->currency ?: 'USD')),
        'branch_id' => $bank->branch_id,
        'account_id' => $bank->account_id,
        'opening_balance' => (float) $bank->opening_balance,
        'is_active' => (bool) $bank->is_active,
        'balance' => $cash->bookBalance($bank),
    ];
})->values();

$byCurrency = [];
foreach ($boxes as $row) {
    if (! $row['is_active']) {
        continue;
    }
    $c = $row['currency'];
    $byCurrency[$c]['cash'] = ($byCurrency[$c]['cash'] ?? 0) + $row['balance'];
}
foreach ($banks as $row) {
    if (! $row['is_active']) {
        continue;
    }
    $c = $row['currency'];
    $byCurrency[$c]['bank'] = ($byCurrency[$c]['bank'] ?? 0) + $row['balance'];
}

ksort($byCurrency);
foreach ($byCurrency as $c => &$row) {
    $row['cash'] = round($row['cash'] ?? 0, 2);
    $row['bank'] = round($row['bank'] ?? 0, 2);
    $row['liquidity'] = round($row['cash'] + $row['bank'], 2);
}
unset($row);

echo json_encode([
    'base_currency' => $base,
    'boxes' => $boxes,
    'banks' => $banks,
    'by_currency' => $byCurrency,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
