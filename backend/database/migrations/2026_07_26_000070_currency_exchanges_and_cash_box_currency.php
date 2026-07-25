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
            $table->string('currency', 8)->default('SYP')->after('opening_balance');
        });

        Schema::create('currency_exchanges', function (Blueprint $table) {
            $table->id();
            $table->string('exchange_number')->unique();
            $table->date('exchange_date');
            $table->foreignId('source_cash_box_id')->constrained('cash_boxes')->restrictOnDelete();
            $table->foreignId('target_cash_box_id')->constrained('cash_boxes')->restrictOnDelete();
            $table->string('source_currency', 8);
            $table->string('target_currency', 8);
            $table->decimal('source_amount', 18, 2);
            $table->decimal('target_amount', 18, 2);
            /** 1 unit of target_currency = exchange_rate units of source_currency */
            $table->decimal('exchange_rate', 18, 8);
            $table->decimal('source_base_amount', 18, 2)->nullable();
            $table->decimal('target_base_amount', 18, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->ensureAccount('11', '1105', 'صندوق العملات الأجنبية', 'Foreign Currency Cash', 'asset', 'debit');
        $this->ensureAccount('4', '4103', 'أرباح فروق أسعار الصرف', 'Foreign Exchange Gain', 'revenue', 'credit');
        $this->ensureAccount('5', '5105', 'خسائر فروق أسعار الصرف', 'Foreign Exchange Loss', 'expense', 'debit');
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchanges');

        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }

    protected function ensureAccount(string $parentCode, string $code, string $name, string $nameEn, string $type, string $nature): void
    {
        if (DB::table('accounts')->where('code', $code)->exists()) {
            return;
        }

        $parent = DB::table('accounts')->where('code', $parentCode)->first();
        if (! $parent) {
            return;
        }

        DB::table('accounts')->insert([
            'code' => $code,
            'name' => $name,
            'name_en' => $nameEn,
            'parent_id' => $parent->id,
            'type' => $type,
            'nature' => $nature,
            'level' => ((int) $parent->level) + 1,
            'is_group' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
