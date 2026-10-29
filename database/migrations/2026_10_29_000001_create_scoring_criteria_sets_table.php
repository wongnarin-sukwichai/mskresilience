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
        Schema::create('scoring_criteria_sets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            // Exactly one set is active (enforced in ScoringCriteriaController::activate()). Locked sets can't be
            // edited until an admin unlocks them.
            $table->boolean('is_active')->default(false);
            $table->boolean('is_locked')->default(false);
            // section → row → column → weight; structure fixed by App\Scoring\CriteriaSchema.
            $table->json('weights');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scoring_criteria_sets');
    }
};
