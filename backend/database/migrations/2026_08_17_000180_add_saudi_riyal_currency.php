<?php

use App\Models\Account;
use App\Services\CashBoxGlService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upsert Saudi Riyal (SAR) and seed missing USD/SYP/TRY/CNY cross rates.
 * Safe for production — does not wipe existing currencies or rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            return;
        }

        $now = now();
        $existing = DB::table('currencies')->where('code', 'SAR')->first();

        if ($existing) {
            DB::table('currencies')->where('code', 'SAR')->update([
                'name' => 'الريال السعودي',
                'name_en' => 'Saudi Riyal',
                'symbol' => 'ر.س',
                'decimal_places' => 2,
                'is_active' => true,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('currencies')->insert([
                'code' => 'SAR',
                'name' => 'الريال السعودي',
                'name_en' => 'Saudi Riyal',
                'symbol' => 'ر.س',
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Account::query()->where('code', '11')->exists()) {
            app(CashBoxGlService::class)->ensureStandardCurrencyAccounts();
        }

        if (! Schema::hasTable('exchange_rates')) {
            return;
        }

        $usdToSyp = 15000.0;
        $usdToTry = round(15000 / 450, 8);
        $usdToCny = 6.75;
        $usdToSar = 3.75;
        $sarToSyp = round($usdToSyp / $usdToSar, 8);
        $sarToTry = round($usdToTry / $usdToSar, 8);
        $sarToCny = round($usdToCny / $usdToSar, 8);
        $rateDate = $now->toDateString();

        $pairs = [
            ['USD', 'SAR', $usdToSar],
            ['SAR', 'USD', round(1 / $usdToSar, 8)],
            ['SAR', 'SYP', $sarToSyp],
            ['SYP', 'SAR', round(1 / $sarToSyp, 8)],
            ['SAR', 'TRY', $sarToTry],
            ['TRY', 'SAR', round(1 / $sarToTry, 8)],
            ['SAR', 'CNY', $sarToCny],
            ['CNY', 'SAR', round(1 / $sarToCny, 8)],
        ];

        foreach ($pairs as [$from, $to, $rate]) {
            $found = DB::table('exchange_rates')
                ->where('from_currency', $from)
                ->where('to_currency', $to)
                ->whereDate('rate_date', $rateDate)
                ->first();

            if ($found) {
                continue;
            }

            DB::table('exchange_rates')->insert([
                'from_currency' => $from,
                'to_currency' => $to,
                'rate' => $rate,
                'rate_date' => $rateDate,
                'notes' => 'سعر افتتاحي — الريال السعودي',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('exchange_rates')) {
            DB::table('exchange_rates')
                ->where(function ($q) {
                    $q->where('from_currency', 'SAR')->orWhere('to_currency', 'SAR');
                })
                ->where('notes', 'سعر افتتاحي — الريال السعودي')
                ->delete();
        }

        if (Schema::hasTable('currencies')) {
            DB::table('currencies')->where('code', 'SAR')->delete();
        }
    }
};
