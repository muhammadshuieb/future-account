<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number', 32)->unique();
            $table->date('entry_date');
            $table->string('description');
            $table->string('reference')->nullable();
            $table->enum('status', ['draft', 'posted', 'void'])->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['entry_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
