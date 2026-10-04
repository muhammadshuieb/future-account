<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unallocated receipts/supplier payments already clear partner AR/AP in GL, but did
 * not raise invoice paid_amount — so partner balance could be zero while invoices
 * still looked open. Track FIFO applications here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('applied_amount', 18, 2)->default(0)->after('amount');
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->decimal('applied_amount', 18, 2)->default(0)->after('amount');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['supplier_payment_id', 'purchase_invoice_id']);
        });

        Schema::create('receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index(['receipt_id', 'sales_invoice_id']);
        });

        // Already-linked documents have fully applied their amount to one invoice.
        DB::table('supplier_payments')
            ->whereNotNull('purchase_invoice_id')
            ->where('status', 'posted')
            ->update(['applied_amount' => DB::raw('amount')]);

        DB::table('receipts')
            ->whereNotNull('sales_invoice_id')
            ->where('status', 'posted')
            ->update(['applied_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocations');
        Schema::dropIfExists('supplier_payment_allocations');

        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('applied_amount');
        });

        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn('applied_amount');
        });
    }
};
