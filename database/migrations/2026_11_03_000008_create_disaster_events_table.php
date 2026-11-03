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
        // งานป้องกันภัยเหตุการณ์ sheet ("งาน ปภ_เหตุการณ์"), the Loss & Damage register: one row per event, keyed
        // by the staff-typed EVENT_ID (EV + 4 digits, owner decision 2026-11-02). Re-importing an EVENT_ID
        // replaces it, but its community and start date may not change. Blank = ไม่มีข้อมูล, never 0.
        Schema::create('disaster_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 6)->unique();
            $table->foreignId('community_id')->constrained()->restrictOnDelete();
            $table->date('event_start');
            $table->date('event_end')->nullable();
            $table->string('primary_hazard', 10);
            $table->string('related_hazards', 50)->nullable();
            $table->boolean('multi_hazard_flag')->nullable();
            $table->text('event_description')->nullable();
            $table->unsignedInteger('l1_houses_damaged_count')->nullable();
            $table->unsignedInteger('l2_injury')->nullable();
            $table->unsignedInteger('l2_death')->nullable();
            $table->decimal('l3_damage_value_thb', 15, 2)->nullable();
            $table->unsignedInteger('l4_recovery_days')->nullable();
            $table->unsignedInteger('l5_repeat_damage_count')->nullable();
            foreach (range(1, 5) as $i) {
                $table->unsignedTinyInteger("r{$i}_observed")->nullable();
            }
            $table->decimal('f1_event_total_budget_thb', 15, 2)->nullable();
            $table->decimal('f2_event_response_cost_thb', 15, 2)->nullable();
            $table->decimal('f3_event_recovery_cost_thb', 15, 2)->nullable();
            $table->decimal('f4_event_risk_reduction_budget_thb', 15, 2)->nullable();
            $table->decimal('f5_event_relief_compensation_thb', 15, 2)->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['community_id', 'event_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disaster_events');
    }
};
