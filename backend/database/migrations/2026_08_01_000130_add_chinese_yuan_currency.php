<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upsert Chinese Yuan (CNY) and seed USD/SYP/TRY cross rates if missing.
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
        $existing = DB::table('currencies')->where('code', 'CNY')->first();

        if ($existing) {
            DB::table('currencies')->where('code', 'CNY')->update([
                'name' => 'اليوان الصيني',
                'name_en' => 'Chinese Yuan',
                'symbol' => 'CN¥',
                'decimal_places' => 2,
                'is_active' => true,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('currencies')->insert([
                'code' => 'CNY',
                'name' => 'اليوان الصيني',
                'name_en' => 'Chinese Yuan',
                'symbol' => 'CN¥',
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasTable('exchange_rates')) {
            return;
        }

        $usdToSyp = 15000.0;
        $usdToTry = round(15000 / 450, 8);
        $usdToCny = 6.75;
        $cnyToSyp = round($usdToSyp / $usdToCny, 8);
        $cnyToTry = round($usdToTry / $usdToCny, 8);
        $rateDate = $now->toDateString();

        $pairs = [
            ['USD', 'CNY', $usdToCny],
            ['CNY', 'USD', round(1 / $usdToCny, 8)],
            ['CNY', 'SYP', $cnyToSyp],
            ['SYP', 'CNY', round(1 / $cnyToSyp, 8)],
            ['CNY', 'TRY', $cnyToTry],
            ['TRY', 'CNY', round(1 / $cnyToTry, 8)],
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
                'notes' => 'سعر افتتاحي — اليوان الصيني',
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
                    $q->where('from_currency', 'CNY')->orWhere('to_currency', 'CNY');
                })
                ->where('notes', 'سعر افتتاحي — اليوان الصيني')
                ->delete();
        }

        if (Schema::hasTable('currencies')) {
            DB::table('currencies')->where('code', 'CNY')->delete();
        }
    }
};
