<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('currency', 8)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('category')->nullable()->index();
            $table->string('row_no', 32)->nullable();
            $table->string('model_no')->nullable()->index();
            $table->string('mp', 64)->nullable();
            $table->text('specs')->nullable();
            $table->string('lens', 128)->nullable();
            $table->decimal('price_super_wholesale', 14, 2)->nullable();
            $table->decimal('price_retail', 14, 2)->nullable();
            $table->decimal('price_wholesale', 14, 2)->nullable();
            $table->boolean('is_category_header')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
