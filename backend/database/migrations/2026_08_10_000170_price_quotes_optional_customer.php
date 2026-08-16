<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Price quotes are commercial offers only (not sales).
 * Allow optional customer so a quote can be drafted without a partner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sales_quotes ALTER COLUMN customer_id DROP NOT NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite: rebuild via temporary nullable column swap is heavy;
            // Laravel schema change handles rebuild for sqlite.
            Schema::table('sales_quotes', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE sales_quotes MODIFY customer_id BIGINT UNSIGNED NULL');
        }

        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('UPDATE sales_quotes SET customer_id = (SELECT id FROM customers ORDER BY id LIMIT 1) WHERE customer_id IS NULL');
            DB::statement('ALTER TABLE sales_quotes ALTER COLUMN customer_id SET NOT NULL');
        } elseif ($driver === 'sqlite') {
            Schema::table('sales_quotes', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            });
        } else {
            DB::statement('ALTER TABLE sales_quotes MODIFY customer_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('sales_quotes', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers');
        });
    }
};
