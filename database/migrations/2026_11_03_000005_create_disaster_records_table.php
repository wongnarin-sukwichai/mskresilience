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
        // งานป้องกันภัย sheet ("งาน ปภ1"), one row per community per ASSESSMENT_DATE (same shape as welfare_records).
        // Re-importing the same (community, date) replaces the row. Value columns are nullable: blank = ไม่มีข้อมูล,
        // never 0. Measured values have 2 decimal places (owner, 2026-11-03), and scores are whole numbers 0–5.
        Schema::create('disaster_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->restrictOnDelete();
            $table->date('assessment_date');
            $table->string('tambon')->nullable();
            $table->string('amphoe')->nullable();
            $table->string('province')->nullable();
            $table->unsignedInteger('population')->nullable();
            $table->unsignedInteger('households')->nullable();
            $table->decimal('area_km2', 10, 2)->nullable();
            foreach (['flood', 'fire', 'storm', 'heat', 'pm25'] as $hazard) {
                $table->unsignedInteger("e_numerator_count_{$hazard}")->nullable();
            }
            $table->decimal('c4_1_volunteer_per_1000', 10, 2)->nullable();
            $table->unsignedInteger('c4_2_training_count')->nullable();
            $table->unsignedTinyInteger('c6_1_response_vehicle_adequacy_score')->nullable();
            $table->unsignedTinyInteger('c6_2_lifesaving_score')->nullable();
            $table->decimal('c6_3_readiness_score', 5, 2)->nullable(); // ร้อยละ 0–100
            $table->unsignedTinyInteger('c6_4_hazard_equipment_adequacy_score')->nullable();
            $table->unsignedTinyInteger('c7_1_flood_score')->nullable();
            $table->unsignedTinyInteger('c7_1_fire_score')->nullable();
            $table->unsignedTinyInteger('c7_1_storm_score')->nullable();
            $table->unsignedTinyInteger('c7_2_safe_place_score')->nullable();
            $table->unsignedTinyInteger('c7_3_drill_score')->nullable();
            $table->unsignedInteger('c8_1_formal_network_count')->nullable();
            $table->decimal('c8_2_response_hours', 10, 2)->nullable();
            $table->unsignedTinyInteger('c8_3_network_types_score')->nullable();
            $table->unsignedTinyInteger('c8_4_joint_experience_score')->nullable();
            $table->unsignedTinyInteger('r1_recovery_plan_score')->nullable();
            $table->unsignedTinyInteger('r2_build_back_better_score')->nullable();
            $table->unsignedTinyInteger('r3_socioeconomic_recov_score')->nullable();
            $table->unsignedTinyInteger('r4_vulner_group_recov_score')->nullable();
            $table->unsignedTinyInteger('r5_learning_sys_improv_score')->nullable();
            $table->decimal('m_budget_avg_3y', 15, 2)->nullable();
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
        Schema::dropIfExists('disaster_records');
    }
};
