<?php

use App\Models\Setting;
use App\Services\AppNotificationService;
use App\Services\BackupDistributionService;
use App\Services\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('syna:backup', function () {
    $notify = app(AppNotificationService::class);
    $distribution = app(BackupDistributionService::class);

    try {
        $meta = app(BackupService::class)->create('auto');
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

    $results = $distribution->distribute($meta['path'], $meta['filename']);
    $hadFailure = false;

    foreach ($results as $dest => $result) {
        if ($result['skipped'] ?? false) {
            $this->comment("{$dest}: skipped (not configured)");
        } elseif ($result['ok'] ?? false) {
            $this->info("{$dest}: uploaded");
        } else {
            $hadFailure = true;
            $this->warn("{$dest}: failed — ".($result['error'] ?? 'unknown'));
        }
    }

    if (! $distribution->googleDriveConfigured()) {
        $notify->notifyAdminsOnceDaily(
            'backup_drive_missing',
            'Google Drive غير مربوط',
            'النسخ الاحتياطي يعمل محلياً فقط. اربط Google Drive من إعدادات الخادم لتلافي فقدان النسخ.',
            ['google_drive' => false],
        );
        $this->warn('Google Drive is not configured.');
    }

    if ($hadFailure) {
        $errors = collect($results)
            ->filter(fn ($r) => ! ($r['skipped'] ?? false) && ! ($r['ok'] ?? false))
            ->map(fn ($r, $dest) => $dest.': '.($r['error'] ?? 'unknown'))
            ->implode(' | ');

        $notify->notifyAdmins(
            'backup_failed',
            'فشل رفع النسخة الاحتياطية',
            'تم إنشاء النسخة محلياً لكن فشل الرفع: '.$errors,
            ['filename' => $meta['filename'], 'distribution' => $results],
        );

        return 1;
    }

    return 0;
})->purpose('Create scheduled Syna Co database backup');

Artisan::command('syna:check-backup-health', function () {
    $distribution = app(BackupDistributionService::class);
    $notify = app(AppNotificationService::class);

    if ($distribution->googleDriveConfigured()) {
        $this->info('Google Drive is configured.');

        return 0;
    }

    $notify->notifyAdminsOnceDaily(
        'backup_drive_missing',
        'Google Drive غير مربوط',
        'تحذير يومي: لم يتم ربط Google Drive للنسخ الاحتياطي. راجع الإعدادات على الخادم (GOOGLE_DRIVE_CREDENTIALS_JSON و GOOGLE_DRIVE_FOLDER_ID).',
        ['google_drive' => false],
    );

    $this->warn('Google Drive is not configured — admin notified.');

    return 0;
})->purpose('Warn admins when Google Drive backup is disconnected');
