<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stub tables for later ERP phases (warehouses, sales, purchases, cash).
 * Kept minimal so Phase 1 can ship without blocking future modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('symbol', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->decimal('cost_price', 18, 2)->default(0);
            $table->decimal('sale_price', 18, 2)->default(0);
            $table->decimal('reorder_level', 18, 3)->default(0);
            $table->boolean('track_batch')->default(false);
            $table->boolean('track_serial')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_boxes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 32)->unique();
            $table->string('name');
            $table->string('job_title')->nullable();
            $table->date('hire_date')->nullable();
            $table->decimal('basic_salary', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('cash_boxes');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('warehouses');
    }
};
