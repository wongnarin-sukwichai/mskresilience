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
        // งานประชาสัมพันธ์ sheet, one row per community per ASSESSMENT_DATE (same shape as welfare_records). Re-importing
        // the same (community, date) replaces the row. Value columns are nullable: blank = ไม่มีข้อมูล, never 0.
        // Scores are whole numbers 0–5 for now (owner, 2026-11-03).
        Schema::create('public_relations_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->restrictOnDelete();
            $table->date('assessment_date');
            $table->unsignedTinyInteger('v_warning_access_gap_score')->nullable();
            $table->unsignedTinyInteger('c4_3_risk_comm_score')->nullable();
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
        Schema::dropIfExists('public_relations_records');
    }
};
