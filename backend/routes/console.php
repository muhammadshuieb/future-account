<?php

use App\Services\BackupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('syna:backup', function () {
    $meta = app(BackupService::class)->create('auto');
    $this->info('Backup created: '.$meta['filename']);

    $results = app(\App\Services\BackupDistributionService::class)->distribute($meta['path'], $meta['filename']);
    foreach ($results as $dest => $result) {
        if ($result['skipped'] ?? false) {
            $this->comment("{$dest}: skipped (not configured)");
        } elseif ($result['ok'] ?? false) {
            $this->info("{$dest}: uploaded");
        } else {
            $this->warn("{$dest}: failed — ".($result['error'] ?? 'unknown'));
        }
    }
})->purpose('Create scheduled Syna Co database backup');
