<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        $mainId = DB::table('cash_boxes')->where('code', 'CASH-01')->value('id')
            ?? DB::table('cash_boxes')->orderBy('id')->value('id');

        if ($mainId) {
            DB::table('cash_boxes')->where('id', $mainId)->update(['is_default' => true]);

            $exists = DB::table('settings')->where('key', 'default_cash_box_id')->exists();
            if ($exists) {
                DB::table('settings')->where('key', 'default_cash_box_id')->update([
                    'value' => (string) $mainId,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('settings')->insert([
                    'key' => 'default_cash_box_id',
                    'value' => (string) $mainId,
                    'group' => 'cash',
                    'type' => 'string',
                    'label' => 'الصندوق الرئيسي الافتراضي',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'default_cash_box_id')->delete();

        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
