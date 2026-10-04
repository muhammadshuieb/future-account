<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales returns already credit AR in GL, but did not allocate that credit to
 * invoice paid_amount (collect-remaining UI) or refund leftover cash from a
 * cash-paid source invoice. Track applications + cash refunds here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->decimal('applied_amount', 18, 2)->default(0)->after('total');
            $table->decimal('refund_amount', 18, 2)->default(0)->after('applied_amount');
            $table->foreignId('cash_box_id')->nullable()->after('refund_amount')->constrained('cash_boxes')->nullOnDelete();
            $table->foreignId('refund_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
        });

        Schema::create('sales_return_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['sales_return_id', 'sales_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_return_allocations');

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_journal_entry_id');
            $table->dropConstrainedForeignId('cash_box_id');
            $table->dropColumn(['applied_amount', 'refund_amount']);
        });
    }
};
