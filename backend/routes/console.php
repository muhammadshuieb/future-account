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
})->purpose('Create scheduled Syna Co database backup');
