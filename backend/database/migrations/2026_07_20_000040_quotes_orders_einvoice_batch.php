<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_number', 64)->unique();
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, confirmed, converted, cancelled
            $table->string('currency', 8)->default('SYP');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_quote_id')->constrained('sales_quotes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 64)->unique();
            $table->date('order_date');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('sales_quote_id')->nullable()->constrained('sales_quotes')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, confirmed, converted, cancelled
            $table->string('currency', 8)->default('SYP');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 64)->unique();
            $table->date('request_date');
            $table->date('required_date')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, approved, converted, cancelled
            $table->string('currency', 8)->default('SYP');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 64)->unique();
            $table->date('order_date');
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status', 20)->default('draft'); // draft, confirmed, converted, cancelled
            $table->string('currency', 8)->default('SYP');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 18, 2)->default(0);
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2);
            $table->decimal('tax_rate', 8, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->string('batch_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('sales_invoices') && ! Schema::hasColumn('sales_invoices', 'e_invoice_uuid')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                $table->uuid('e_invoice_uuid')->nullable()->unique()->after('invoice_number');
                $table->foreignId('sales_order_id')->nullable()->after('branch_id')->constrained('sales_orders')->nullOnDelete();
            });
        }

        if (Schema::hasTable('purchase_invoices') && ! Schema::hasColumn('purchase_invoices', 'purchase_order_id')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->foreignId('purchase_order_id')->nullable()->after('branch_id')->constrained('purchase_orders')->nullOnDelete();
            });
        }

        $lineTables = [
            'sales_invoice_lines',
            'purchase_invoice_lines',
            'sales_return_lines',
            'purchase_return_lines',
        ];

        foreach ($lineTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'batch_no')) {
                    $table->string('batch_no')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'serial_no')) {
                    $table->string('serial_no')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_invoice_lines', 'purchase_invoice_lines', 'sales_return_lines', 'purchase_return_lines'] as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                    foreach (['batch_no', 'serial_no'] as $col) {
                        if (Schema::hasColumn($tableName, $col)) {
                            $table->dropColumn($col);
                        }
                    }
                });
            }
        }

        if (Schema::hasTable('purchase_invoices') && Schema::hasColumn('purchase_invoices', 'purchase_order_id')) {
            Schema::table('purchase_invoices', function (Blueprint $table) {
                $table->dropConstrainedForeignId('purchase_order_id');
            });
        }

        if (Schema::hasTable('sales_invoices')) {
            Schema::table('sales_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('sales_invoices', 'sales_order_id')) {
                    $table->dropConstrainedForeignId('sales_order_id');
                }
                if (Schema::hasColumn('sales_invoices', 'e_invoice_uuid')) {
                    $table->dropColumn('e_invoice_uuid');
                }
            });
        }

        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('sales_quote_items');
        Schema::dropIfExists('sales_quotes');
    }
};
