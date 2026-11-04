<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_returns', 'customs_amount')) {
                $table->decimal('customs_amount', 18, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('purchase_returns', 'transport_fees')) {
                $table->decimal('transport_fees', 18, 2)->default(0)->after('customs_amount');
            }
            if (! Schema::hasColumn('purchase_returns', 'fines_amount')) {
                $table->decimal('fines_amount', 18, 2)->default(0)->after('transport_fees');
            }
            if (! Schema::hasColumn('purchase_returns', 'other_fees')) {
                $table->decimal('other_fees', 18, 2)->default(0)->after('fines_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            foreach (['customs_amount', 'transport_fees', 'fines_amount', 'other_fees'] as $col) {
                if (Schema::hasColumn('purchase_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
