<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * unit_cost was decimal(18,2) which rounds high-qty / low-price lines
 * (e.g. 0.01926 → 0.02). line_total kept the true amount, so purchase
 * returns rebuilt from qty×unit_cost no longer matched the invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (['purchase_invoice_lines', 'purchase_return_lines'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'unit_cost')) {
                continue;
            }
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE {$table} MODIFY unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0");
            } elseif ($driver === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN unit_cost TYPE DECIMAL(18,6)");
            } else {
                // sqlite / others: best-effort via doctrine-less recreate is unnecessary in tests
                // that already accept float storage.
            }
        }

        // Restore precise unit cost from stored line_total where tax is not involved.
        if (Schema::hasTable('purchase_invoice_lines')) {
            DB::statement('
                UPDATE purchase_invoice_lines
                SET unit_cost = ROUND(line_total / quantity, 6)
                WHERE quantity > 0
                  AND COALESCE(tax_rate, 0) = 0
                  AND ABS(ROUND(quantity * unit_cost, 2) - line_total) > 0.001
            ');
        }

        if (Schema::hasTable('purchase_return_lines')) {
            DB::statement('
                UPDATE purchase_return_lines
                SET unit_cost = ROUND(line_total / quantity, 6)
                WHERE quantity > 0
                  AND ABS(ROUND(quantity * unit_cost, 2) - line_total) > 0.001
            ');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (['purchase_invoice_lines', 'purchase_return_lines'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'unit_cost')) {
                continue;
            }
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE {$table} MODIFY unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0");
            } elseif ($driver === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN unit_cost TYPE DECIMAL(18,2)");
            }
        }
    }
};
