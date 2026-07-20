<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo')->nullable();
            $table->unsignedSmallInteger('line_order')->default(0);
            $table->timestamps();

            $table->index(['journal_entry_id', 'line_order']);
            $table->index('account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_details');
    }
};
