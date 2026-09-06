<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_invoices') && ! Schema::hasColumn('purchase_invoices', 'discount_amount')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->decimal('discount_amount', 18, 2)->default(0)->after('subtotal');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_invoices') && Schema::hasColumn('purchase_invoices', 'discount_amount')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
    }
};
