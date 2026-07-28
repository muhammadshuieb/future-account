<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_invoices', 'customs_amount')) {
                $table->decimal('customs_amount', 18, 2)->default(0)->after('tax_amount');
            }
            if (! Schema::hasColumn('purchase_invoices', 'transport_fees')) {
                $table->decimal('transport_fees', 18, 2)->default(0)->after('customs_amount');
            }
            if (! Schema::hasColumn('purchase_invoices', 'fines_amount')) {
                $table->decimal('fines_amount', 18, 2)->default(0)->after('transport_fees');
            }
            if (! Schema::hasColumn('purchase_invoices', 'other_fees')) {
                $table->decimal('other_fees', 18, 2)->default(0)->after('fines_amount');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table) {
            foreach (['customs_amount', 'transport_fees', 'fines_amount', 'other_fees'] as $col) {
                if (Schema::hasColumn('purchase_invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
