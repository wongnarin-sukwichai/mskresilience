<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // งาน KPI sheet ("KPI_เพิ่มเติมได้"), one row per community per ASSESSMENT_DATE (same shape as
        // welfare_records). Counts of people reached per group. A count above its group only warns on import
        // (owner decision 2026-11-03), so values aren't capped here. Blank = ไม่มีข้อมูล, never 0.
        Schema::create('kpi_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->restrictOnDelete();
            $table->date('assessment_date');
            $table->unsignedInteger('kpi_low_income_exit_verified_count')->nullable();
            $table->unsignedInteger('kpi_low_income_followup_count')->nullable();
            $table->unsignedInteger('kpi_elderly_plan_count')->nullable();
            $table->unsignedInteger('kpi_disabled_plan_count')->nullable();
            $table->unsignedInteger('kpi_child_plan_count')->nullable();
            $table->unsignedInteger('kpi_medical_plan_ready_count')->nullable();
            $table->unsignedInteger('kpi_bedridden_plan_ready_count')->nullable();
            $table->unsignedInteger('kpi_assisted_evac_ready_count')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['community_id', 'assessment_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_records');
    }
};
