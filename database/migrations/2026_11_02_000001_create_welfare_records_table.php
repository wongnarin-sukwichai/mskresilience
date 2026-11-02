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
        // งานสวัสดิการสังคม sheet, one row per community per ASSESSMENT_DATE. Re-importing the same (community,
        // date) replaces the row (owner decision 2026-11-02). Value columns are nullable: blank = ไม่มีข้อมูล,
        // never 0.
        Schema::create('welfare_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_id')->constrained()->restrictOnDelete();
            $table->date('assessment_date');
            $table->unsignedInteger('v_elderly_count')->nullable();
            $table->unsignedInteger('v_child_count')->nullable();
            $table->unsignedInteger('v_disabled_count')->nullable();
            $table->unsignedInteger('v_low_income_count')->nullable();
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
        Schema::dropIfExists('welfare_records');
    }
};
