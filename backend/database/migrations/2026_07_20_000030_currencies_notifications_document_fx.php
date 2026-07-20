<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->string('code', 8)->unique();
                $table->string('name');
                $table->string('name_en')->nullable();
                $table->string('symbol', 16)->nullable();
                $table->unsignedTinyInteger('decimal_places')->default(2);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('exchange_rates')) {
            Schema::create('exchange_rates', function (Blueprint $table) {
                $table->id();
                $table->string('from_currency', 8);
                $table->string('to_currency', 8);
                $table->decimal('rate', 18, 8);
                $table->date('rate_date');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['from_currency', 'to_currency', 'rate_date']);
                $table->index(['to_currency', 'rate_date']);
            });
        }

        if (! Schema::hasTable('app_notifications')) {
            Schema::create('app_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('type', 64)->default('info');
                $table->string('title');
                $table->text('body')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        // Drop leftover incomplete notifications table from a failed prior migrate, if empty of Laravel structure
        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'notifiable_type')) {
            Schema::drop('notifications');
        }

        $moneyDocs = [
            'sales_invoices',
            'purchase_invoices',
            'sales_returns',
            'purchase_returns',
            'receipts',
            'supplier_payments',
            'journal_entries',
        ];

        foreach ($moneyDocs as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'currency')) {
                    $table->string('currency', 8)->default('SYP');
                }
                if (! Schema::hasColumn($tableName, 'exchange_rate')) {
                    $table->decimal('exchange_rate', 18, 8)->default(1);
                }
                if (! Schema::hasColumn($tableName, 'base_amount')) {
                    $table->decimal('base_amount', 18, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        $moneyDocs = [
            'sales_invoices',
            'purchase_invoices',
            'sales_returns',
            'purchase_returns',
            'receipts',
            'supplier_payments',
            'journal_entries',
        ];

        foreach ($moneyDocs as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['base_amount', 'exchange_rate', 'currency'] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
    }
};
