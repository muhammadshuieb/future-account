<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('currency', 8)->default('SAR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->text('notes')->nullable()->after('location');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('inventory_account_id')->nullable()->after('unit_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('cogs_account_id')->nullable()->after('inventory_account_id')->constrained('accounts')->nullOnDelete();
            $table->foreignId('sales_account_id')->nullable()->after('cogs_account_id')->constrained('accounts')->nullOnDelete();
            $table->text('description')->nullable()->after('name');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->string('address')->nullable()->after('email');
            $table->text('notes')->nullable();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->decimal('credit_limit', 18, 2)->default(0)->after('tax_number');
            $table->string('address')->nullable()->after('email');
            $table->text('notes')->nullable();
        });

        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->decimal('opening_balance', 18, 2)->default(0);
        });

        Schema::table('banks', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->string('currency', 8)->default('SAR');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete();
            $table->string('department')->nullable()->after('job_title');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('quantity', 18, 3)->default(0);
            $table->string('batch_no')->default('');
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id', 'batch_no']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_number', 64)->unique();
            $table->date('movement_date');
            $table->string('type', 32); // in, out, transfer, adjustment, count
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_number', 64)->unique();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('count_date');
            $table->string('status', 20)->default('draft'); // draft, posted
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('system_qty', 18, 3)->default(0);
            $table->decimal('counted_qty', 18, 3)->default(0);
            $table->decimal('difference', 18, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 64)->unique();
            $table->date('transfer_date');
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('warehouse_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_transfer_id')->constrained('warehouse_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->timestamps();
        });

        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 64)->unique();
            $table->date('invoice_date');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->decimal('cost_price', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 64)->unique();
            $table->date('return_date');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 64)->unique();
            $table->date('receipt_date');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('sales_invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->nullOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('method', 20)->default('cash'); // cash, bank
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 64)->unique();
            $table->date('invoice_date');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 64)->unique();
            $table->date('return_date');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 64)->unique();
            $table->date('payment_date');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('purchase_invoice_id')->nullable()->constrained('purchase_invoices')->nullOnDelete();
            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->nullOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained('banks')->nullOnDelete();
            $table->string('method', 20)->default('cash');
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number', 64)->unique();
            $table->date('transfer_date');
            $table->string('from_type', 20); // cash_box, bank
            $table->unsignedBigInteger('from_id');
            $table->string('to_type', 20);
            $table->unsignedBigInteger('to_id');
            $table->decimal('amount', 18, 2);
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks');
            $table->date('statement_date');
            $table->decimal('statement_balance', 18, 2);
            $table->decimal('book_balance', 18, 2)->default(0);
            $table->decimal('difference', 18, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('status', 20)->default('present'); // present, absent, leave
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'attendance_date']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('leave_type', 40)->default('annual');
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->decimal('basic_salary', 18, 2);
            $table->decimal('allowances', 18, 2)->default(0);
            $table->decimal('deductions', 18, 2)->default(0);
            $table->decimal('net_salary', 18, 2);
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'period']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index(['auditable_type', 'auditable_id']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('salary_records');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('cash_transfers');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::dropIfExists('sales_invoice_lines');
        Schema::dropIfExists('sales_invoices');
        Schema::dropIfExists('warehouse_transfer_lines');
        Schema::dropIfExists('warehouse_transfers');
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['department', 'phone', 'email', 'birth_date']);
        });
        Schema::table('banks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['opening_balance', 'currency']);
        });
        Schema::table('cash_boxes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['opening_balance']);
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['credit_limit', 'address', 'notes']);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['address', 'notes']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_account_id');
            $table->dropConstrainedForeignId('cogs_account_id');
            $table->dropConstrainedForeignId('sales_account_id');
            $table->dropColumn(['description']);
        });
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['notes']);
        });

        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
