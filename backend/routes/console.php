<?php

use App\Models\Setting;
use App\Services\AppNotificationService;
use App\Services\BackupDistributionService;
use App\Services\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('syna:backup', function () {
    $notify = app(AppNotificationService::class);
    $distribution = app(BackupDistributionService::class);
    $backups = app(BackupService::class);
    $pendingInvoice = \App\Services\BackupTrigger::consume();
    if ($pendingInvoice) {
        $this->comment('Trigger: after invoice ('.$pendingInvoice['reason'].')');
    }

    try {
        $label = $pendingInvoice ? 'invoice' : 'auto';
        $meta = $backups->create($label);
        $this->info('Backup created: '.$meta['filename']);
    } catch (\Throwable $e) {
        $this->error('Backup failed: '.$e->getMessage());
        $notify->notifyAdmins(
            'backup_failed',
            'فشل النسخ الاحتياطي',
            'تعذّر إنشاء النسخة الاحتياطية التلقائية: '.$e->getMessage(),
            ['error' => $e->getMessage()],
        );

        return 1;
    }

    $hadFailure = false;
    $allDist = [];
    $cmd = $this;
    $reportDist = function (string $label, array $results) use (&$hadFailure, &$allDist, $cmd): void {
        $allDist[$label] = $results;
        foreach ($results as $dest => $result) {
            if ($result['skipped'] ?? false) {
                $cmd->comment("{$label}/{$dest}: skipped (not configured)");
            } elseif ($result['ok'] ?? false) {
                $cmd->info("{$label}/{$dest}: uploaded");
            } else {
                $hadFailure = true;
                $cmd->warn("{$label}/{$dest}: failed — ".($result['error'] ?? 'unknown'));
            }
        }
    };

    $reportDist('db', $distribution->distribute(
        $meta['path'],
        $meta['filename'],
        $meta['caption'] ?? BackupService::CAPTION_DB,
    ));

    if (! empty($meta['excel_path']) && ! empty($meta['excel_filename'])) {
        $this->info('Excel: '.$meta['excel_filename']);
        $reportDist('excel', $distribution->distribute(
            $meta['excel_path'],
            $meta['excel_filename'],
            $meta['excel_caption'] ?? BackupService::CAPTION_EXCEL,
        ));
    } elseif (! empty($meta['excel_error'])) {
        $this->warn('Excel companion failed: '.$meta['excel_error']);
    }

    if (! empty($meta['code_path']) && ! empty($meta['code_filename'])) {
        $this->info('Source code: '.$meta['code_filename']);
        $reportDist('code', $distribution->distribute(
            $meta['code_path'],
            $meta['code_filename'],
            $meta['code_caption'] ?? \App\Services\SourceCodeArchiveService::TELEGRAM_CAPTION,
        ));
    } elseif (! empty($meta['code_error'])) {
        $this->warn('Source archive missing: '.$meta['code_error']);
    }

    if (! $distribution->googleDriveConfigured()) {
        $notify->notifyAdminsOnceDaily(
            'backup_drive_missing',
            'Google Drive غير مربوط',
                'النسخ الاحتياطي يعمل محلياً فقط. اربط Google Drive من الإعدادات ← النسخ الاحتياطي لتلافي فقدان النسخ.',
            ['google_drive' => false],
        );
        $this->warn('Google Drive is not configured.');
    }

    // Retention runs only after a successful local backup (fresh proof exists).
    try {
        $cleanup = $backups->pruneOldBackups();
        if ($cleanup['skipped'] ?? false) {
            $this->warn($cleanup['message'] ?? 'Retention skipped');
        } elseif (($cleanup['deleted_count'] ?? 0) > 0) {
            $this->info($cleanup['message'] ?? 'Old backups pruned');
        } else {
            $this->comment($cleanup['message'] ?? 'No old backups to prune');
        }
    } catch (\Throwable $e) {
        Log::warning('Backup retention failed: '.$e->getMessage());
        $this->warn('Retention cleanup failed: '.$e->getMessage());
    }

    if ($hadFailure) {
        $errors = collect($allDist)
            ->flatMap(fn ($results, $label) => collect($results)
                ->filter(fn ($r) => ! ($r['skipped'] ?? false) && ! ($r['ok'] ?? false))
                ->map(fn ($r, $dest) => "{$label}/{$dest}: ".($r['error'] ?? 'unknown')))
            ->implode(' | ');

        $notify->notifyAdmins(
            'backup_failed',
            'فشل رفع النسخة الاحتياطية',
            'تم إنشاء النسخة محلياً لكن فشل الرفع: '.$errors,
            ['filename' => $meta['filename'], 'distribution' => $allDist],
        );

        return 1;
    }

    return 0;
})->purpose('Create scheduled Syna Co database backup');

Artisan::command('syna:send-source-archive', function () {
    $archive = app(\App\Services\SourceCodeArchiveService::class);
    $distribution = app(BackupDistributionService::class);

    try {
        $meta = $archive->ensureLatest(env('SOURCE_ARCHIVE_ROOT') ?: null);
        $this->info('Source archive ready: '.$meta['filename'].' ('.$meta['size_human'].')');
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    if (! $distribution->telegramConfigured() && ! $distribution->googleDriveConfigured()) {
        $this->comment('No remote destinations configured — archive kept locally only.');

        return 0;
    }

    $results = $distribution->distribute(
        $meta['path'],
        $meta['filename'],
        \App\Services\SourceCodeArchiveService::TELEGRAM_CAPTION.' — '.now()->format('Y-m-d H:i'),
    );

    foreach ($results as $dest => $result) {
        if ($result['skipped'] ?? false) {
            $this->comment("{$dest}: skipped");
        } elseif ($result['ok'] ?? false) {
            $this->info("{$dest}: uploaded");
        } else {
            $this->warn("{$dest}: failed — ".($result['error'] ?? 'unknown'));

            return 1;
        }
    }

    return 0;
})->purpose('Ensure retained source zip exists and send it to backup destinations');

Artisan::command('syna:backfill-cash-box-gl {--dry-run : Preview links without saving}', function () {
    $gl = app(\App\Services\CashBoxGlService::class);
    $dry = (bool) $this->option('dry-run');
    $report = $gl->backfillAll(dryRun: $dry);

    $this->info($dry ? 'Dry-run: cash box ↔ GL account links' : 'Applied: cash box ↔ GL account links');
    $this->table(
        ['id', 'code', 'currency', 'account_id', 'account_code', 'action'],
        collect($report)->map(fn (array $row) => [
            $row['id'],
            $row['code'],
            $row['currency'],
            $row['account_id'],
            $row['account_code'],
            $row['action'],
        ])->all()
    );

    $linked = collect($report)->whereIn('action', ['link', 'relink'])->count();
    $this->comment("Boxes needing change: {$linked} / ".count($report));

    return 0;
})->purpose('Link each cash box to an independent GL account for currency exchange');

Artisan::command('syna:repair-sales-return-credit {--customer= : Customer id or name fragment} {--dry-run : Preview without writing}', function () {
    $sales = app(\App\Services\SalesService::class);
    $dry = (bool) $this->option('dry-run');
    $customerOpt = $this->option('customer');

    $query = \App\Models\SalesReturn::query()
        ->with(['customer', 'invoice'])
        ->where('status', 'posted')
        ->whereRaw('(total - COALESCE(applied_amount, 0) - COALESCE(refund_amount, 0)) > 0.001');

    if ($customerOpt !== null && $customerOpt !== '') {
        if (ctype_digit((string) $customerOpt)) {
            $query->where('customer_id', (int) $customerOpt);
        } else {
            $query->whereHas('customer', fn ($q) => $q->where('name', 'like', '%'.$customerOpt.'%'));
        }
    }

    $returns = $query->orderBy('id')->get();
    if ($returns->isEmpty()) {
        $this->info('No posted sales returns with unsettled credit.');

        return 0;
    }

    $user = \App\Models\User::query()->where('is_active', true)->orderBy('id')->first();
    if (! $user) {
        $this->error('No active user available to attribute settlement journals.');

        return 1;
    }

    $rows = [];
    foreach ($returns as $ret) {
        $before = [
            'applied' => (float) $ret->applied_amount,
            'refund' => (float) $ret->refund_amount,
            'open' => $ret->unallocatedAmount(),
        ];

        if ($dry) {
            $rows[] = [
                $ret->id,
                $ret->return_number,
                $ret->customer?->name,
                $before['open'],
                $before['applied'],
                $before['refund'],
                'dry-run',
            ];
            continue;
        }

        $fresh = $sales->settleReturnCredit($ret, $user);
        $rows[] = [
            $ret->id,
            $ret->return_number,
            $ret->customer?->name,
            $before['open'],
            (float) $fresh->applied_amount,
            (float) $fresh->refund_amount,
            'settled',
        ];
    }

    $this->info($dry ? 'Dry-run: unsettled sales-return credit' : 'Repaired sales-return credit settlement');
    $this->table(
        ['id', 'number', 'customer', 'was_open', 'applied', 'refund', 'action'],
        $rows
    );

    return 0;
})->purpose('Allocate/refund posted sales-return credit that never settled invoices or cash');

Artisan::command('syna:repair-purchase-return-credit {--supplier= : Supplier id or name fragment} {--dry-run : Preview without writing}', function () {
    $purchases = app(\App\Services\PurchaseService::class);
    $dry = (bool) $this->option('dry-run');
    $supplierOpt = $this->option('supplier');

    $query = \App\Models\PurchaseReturn::query()
        ->with(['supplier', 'invoice'])
        ->where('status', 'posted')
        ->whereRaw('(total - COALESCE(applied_amount, 0) - COALESCE(refund_amount, 0)) > 0.001');

    if ($supplierOpt !== null && $supplierOpt !== '') {
        if (ctype_digit((string) $supplierOpt)) {
            $query->where('supplier_id', (int) $supplierOpt);
        } else {
            $query->whereHas('supplier', fn ($q) => $q->where('name', 'like', '%'.$supplierOpt.'%'));
        }
    }

    $returns = $query->orderBy('id')->get();
    if ($returns->isEmpty()) {
        $this->info('No posted purchase returns with unsettled credit.');

        return 0;
    }

    $user = \App\Models\User::query()->where('is_active', true)->orderBy('id')->first();
    if (! $user) {
        $this->error('No active user available to attribute settlement journals.');

        return 1;
    }

    $rows = [];
    foreach ($returns as $ret) {
        $before = [
            'applied' => (float) $ret->applied_amount,
            'refund' => (float) $ret->refund_amount,
            'open' => $ret->unallocatedAmount(),
        ];

        if ($dry) {
            $rows[] = [
                $ret->id,
                $ret->return_number,
                $ret->supplier?->name,
                $before['open'],
                $before['applied'],
                $before['refund'],
                'dry-run',
            ];
            continue;
        }

        $fresh = $purchases->settleReturnCredit($ret, $user);
        $rows[] = [
            $ret->id,
            $ret->return_number,
            $ret->supplier?->name,
            $before['open'],
            (float) $fresh->applied_amount,
            (float) $fresh->refund_amount,
            'settled',
        ];
    }

    $this->info($dry ? 'Dry-run: unsettled purchase-return credit' : 'Repaired purchase-return credit settlement');
    $this->table(
        ['id', 'number', 'supplier', 'was_open', 'applied', 'refund', 'action'],
        $rows
    );

    return 0;
})->purpose('Allocate/refund posted purchase-return credit that never settled invoices or cash');

Artisan::command('syna:repair-supplier-payment-allocation {--supplier= : Supplier id or name fragment} {--dry-run : Preview without writing}', function () {
    $purchases = app(\App\Services\PurchaseService::class);
    $dry = (bool) $this->option('dry-run');
    $supplierOpt = $this->option('supplier');

    $query = \App\Models\SupplierPayment::query()
        ->with(['supplier', 'invoice'])
        ->where('status', 'posted')
        ->whereRaw('(amount - COALESCE(applied_amount, 0)) > 0.001');

    if ($supplierOpt !== null && $supplierOpt !== '') {
        if (ctype_digit((string) $supplierOpt)) {
            $query->where('supplier_id', (int) $supplierOpt);
        } else {
            $query->whereHas('supplier', fn ($q) => $q->where('name', 'like', '%'.$supplierOpt.'%'));
        }
    }

    $payments = $query->orderBy('id')->get();
    if ($payments->isEmpty()) {
        $this->info('No posted supplier payments with unallocated amount.');

        return 0;
    }

    $rows = [];
    foreach ($payments as $pay) {
        $before = $pay->unallocatedAmount();
        if ($dry) {
            $rows[] = [$pay->id, $pay->payment_number, $pay->supplier?->name, $before, (float) $pay->applied_amount, 'dry-run'];
            continue;
        }
        $fresh = $purchases->settlePaymentAllocation($pay);
        $rows[] = [$pay->id, $pay->payment_number, $pay->supplier?->name, $before, (float) $fresh->applied_amount, 'settled'];
    }

    $this->info($dry ? 'Dry-run: unallocated supplier payments' : 'Repaired supplier-payment invoice allocation');
    $this->table(['id', 'number', 'supplier', 'was_open', 'applied', 'action'], $rows);

    return 0;
})->purpose('FIFO-allocate posted supplier payments that never raised invoice paid_amount');

Artisan::command('syna:repair-receipt-allocation {--customer= : Customer id or name fragment} {--dry-run : Preview without writing}', function () {
    $sales = app(\App\Services\SalesService::class);
    $dry = (bool) $this->option('dry-run');
    $customerOpt = $this->option('customer');

    $query = \App\Models\Receipt::query()
        ->with(['customer', 'invoice'])
        ->where('status', 'posted')
        ->whereRaw('(amount - COALESCE(applied_amount, 0)) > 0.001');

    if ($customerOpt !== null && $customerOpt !== '') {
        if (ctype_digit((string) $customerOpt)) {
            $query->where('customer_id', (int) $customerOpt);
        } else {
            $query->whereHas('customer', fn ($q) => $q->where('name', 'like', '%'.$customerOpt.'%'));
        }
    }

    $receipts = $query->orderBy('id')->get();
    if ($receipts->isEmpty()) {
        $this->info('No posted receipts with unallocated amount.');

        return 0;
    }

    $rows = [];
    foreach ($receipts as $rc) {
        $before = $rc->unallocatedAmount();
        if ($dry) {
            $rows[] = [$rc->id, $rc->receipt_number, $rc->customer?->name, $before, (float) $rc->applied_amount, 'dry-run'];
            continue;
        }
        $fresh = $sales->settleReceiptAllocation($rc);
        $rows[] = [$rc->id, $rc->receipt_number, $rc->customer?->name, $before, (float) $fresh->applied_amount, 'settled'];
    }

    $this->info($dry ? 'Dry-run: unallocated receipts' : 'Repaired receipt invoice allocation');
    $this->table(['id', 'number', 'customer', 'was_open', 'applied', 'action'], $rows);

    return 0;
})->purpose('FIFO-allocate posted receipts that never raised invoice paid_amount');
