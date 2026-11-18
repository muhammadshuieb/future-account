<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equity_partners', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->decimal('share_percent', 8, 4)->default(0);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('phone', 50)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('profit_distributions', function (Blueprint $table) {
            $table->id();
            $table->string('distribution_number', 32)->unique();
            $table->date('distribution_date');
            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();
            $table->decimal('total_amount', 18, 2);
            $table->string('currency', 8)->default('USD');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('base_amount', 18, 2);
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('profit_distribution_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profit_distribution_id')->constrained('profit_distributions')->cascadeOnDelete();
            $table->foreignId('equity_partner_id')->constrained('equity_partners')->restrictOnDelete();
            $table->decimal('share_percent', 8, 4)->default(0);
            $table->decimal('amount', 18, 2);
            $table->decimal('base_amount', 18, 2);
            $table->timestamps();

            $table->unique(['profit_distribution_id', 'equity_partner_id'], 'pd_lines_distribution_partner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_distribution_lines');
        Schema::dropIfExists('profit_distributions');
        Schema::dropIfExists('equity_partners');
    }
};
