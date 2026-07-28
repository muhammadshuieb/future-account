<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_invoices') && ! Schema::hasColumn('sales_invoices', 'discount_amount')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->decimal('discount_amount', 18, 2)->default(0)->after('subtotal');
            });
        }

        // Contra-revenue: type revenue / nature credit; debits reduce net sales in P&L.
        $this->ensureAccount('4', '4104', 'حسومات المبيعات', 'Sales Discounts', 'revenue', 'credit');
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_invoices') && Schema::hasColumn('sales_invoices', 'discount_amount')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }
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
