<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_transfer_lines')) {
            Schema::table('warehouse_transfer_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('warehouse_transfer_lines', 'batch_no')) {
                    $table->string('batch_no')->nullable()->after('quantity');
                }
                if (! Schema::hasColumn('warehouse_transfer_lines', 'serial_no')) {
                    $table->string('serial_no')->nullable()->after('batch_no');
                }
            });
        }

        if (Schema::hasTable('inventory_count_lines')) {
            Schema::table('inventory_count_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_count_lines', 'batch_no')) {
                    $table->string('batch_no')->nullable()->after('product_id');
                }
                if (! Schema::hasColumn('inventory_count_lines', 'serial_no')) {
                    $table->string('serial_no')->nullable()->after('batch_no');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['warehouse_transfer_lines', 'inventory_count_lines'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['batch_no', 'serial_no'] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
