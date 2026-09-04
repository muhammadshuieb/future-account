<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_quote_items', 'product_name')) {
            return;
        }

        Schema::table('sales_quote_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('sales_quote_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->change();
            $table->string('product_name')->nullable()->after('product_id');
            $table->string('brand')->nullable()->after('product_name');
            $table->string('model')->nullable()->after('brand');
        });

        Schema::table('sales_quote_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales_quote_items', 'product_name')) {
            return;
        }

        Schema::table('sales_quote_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_name', 'brand', 'model']);
        });

        Schema::table('sales_quote_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable(false)->change();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
