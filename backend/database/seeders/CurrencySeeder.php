<?php

namespace Database\Seeders;

use App\Services\CurrencyService;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        app(CurrencyService::class)->ensureSeeded();
        app(CurrencyService::class)->seedDemoRates();
    }
}
