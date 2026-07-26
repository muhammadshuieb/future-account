<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switch system base currency from SYP to USD without rewriting historical documents.
 * Existing invoices/receipts keep their stored currency and base_amount values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        $existing = DB::table('settings')->where('key', 'currency')->first();

        if ($existing) {
            DB::table('settings')->where('key', 'currency')->update([
                'value' => 'USD',
                'group' => 'finance',
                'type' => 'string',
                'label' => $existing->label ?: 'العملة الأساسية',
                'updated_at' => $now,
            ]);
        } else {
            DB::table('settings')->insert([
                'key' => 'currency',
                'value' => 'USD',
                'group' => 'finance',
                'type' => 'string',
                'label' => 'العملة الأساسية',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Cache::forget('setting.currency');
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->where('key', 'currency')->update([
            'value' => 'SYP',
            'updated_at' => now(),
        ]);

        Cache::forget('setting.currency');
    }
};
