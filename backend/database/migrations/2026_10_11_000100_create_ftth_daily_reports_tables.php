<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ftth_daily_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_number', 64)->unique();
            $table->date('report_date');
            $table->string('project_name')->default('مشروع FTTH – الأعمال المدنية');
            $table->string('phase')->default('الحفر وتمديد الدكت وتركيب المنهولات');
            $table->string('weather')->nullable();
            $table->string('area_sector')->nullable();
            $table->string('supervisor')->nullable();
            $table->string('contractor_team')->nullable();
            $table->unsignedInteger('workers_count')->default(0);
            $table->string('start_time', 16)->nullable();
            $table->string('end_time', 16)->nullable();
            $table->json('works')->nullable();
            $table->json('materials')->nullable();
            $table->json('next_day_plan')->nullable();
            $table->json('next_day_resources')->nullable();
            $table->text('obstacles')->nullable();
            $table->text('remediation')->nullable();
            $table->text('quality_notes')->nullable();
            $table->text('safety_notes')->nullable();
            $table->text('attachments_notes')->nullable();
            $table->text('next_day_obstacles')->nullable();
            $table->text('next_day_coordination')->nullable();
            $table->text('next_day_safety')->nullable();
            $table->text('next_day_inspection')->nullable();
            $table->text('next_day_goal')->nullable();
            $table->string('prepared_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['report_date', 'status']);
        });

        Schema::create('ftth_sectors', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('name')->nullable();
            $table->string('from_point')->nullable();
            $table->string('to_point')->nullable();
            $table->decimal('planned_length_m', 18, 3)->default(0);
            $table->decimal('excavation_done_m', 18, 3)->default(0);
            $table->decimal('duct_done_m', 18, 3)->default(0);
            $table->unsignedInteger('manholes_done')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ftth_sectors');
        Schema::dropIfExists('ftth_daily_reports');
    }
};
