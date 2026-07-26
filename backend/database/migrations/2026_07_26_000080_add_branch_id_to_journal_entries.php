<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();
        });

        // Best-effort backfill from invoice references (portable across MySQL/SQLite).
        if (Schema::hasTable('sales_invoices') && Schema::hasColumn('sales_invoices', 'branch_id')) {
            $pairs = DB::table('sales_invoices')
                ->whereNotNull('branch_id')
                ->whereNotNull('invoice_number')
                ->get(['invoice_number', 'branch_id']);
            foreach ($pairs as $row) {
                DB::table('journal_entries')
                    ->whereNull('branch_id')
                    ->where('reference', $row->invoice_number)
                    ->update(['branch_id' => $row->branch_id]);
            }
        }

        if (Schema::hasTable('purchase_invoices') && Schema::hasColumn('purchase_invoices', 'branch_id')) {
            $pairs = DB::table('purchase_invoices')
                ->whereNotNull('branch_id')
                ->whereNotNull('invoice_number')
                ->get(['invoice_number', 'branch_id']);
            foreach ($pairs as $row) {
                DB::table('journal_entries')
                    ->whereNull('branch_id')
                    ->where('reference', $row->invoice_number)
                    ->update(['branch_id' => $row->branch_id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
