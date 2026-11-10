<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Criteria by fiscal year (owner decision 2026-11-06): each set gets a required start ปีงบประมาณ (พ.ศ.), which
 * replaces the "exactly one active set" model, so is_active goes. Existing sets are numbered from 2568 (the
 * fiscal year of the earliest data, Jan 2025) in id order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scoring_criteria_sets', function (Blueprint $table) {
            $table->unsignedSmallInteger('start_fiscal_year')->nullable()->after('name');
        });

        foreach (DB::table('scoring_criteria_sets')->orderBy('id')->pluck('id')->values() as $i => $id) {
            DB::table('scoring_criteria_sets')->where('id', $id)->update(['start_fiscal_year' => 2568 + $i]);
        }

        Schema::table('scoring_criteria_sets', function (Blueprint $table) {
            $table->unsignedSmallInteger('start_fiscal_year')->nullable(false)->change();
            $table->unique('start_fiscal_year');
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('scoring_criteria_sets', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->after('name');
        });
        Schema::table('scoring_criteria_sets', function (Blueprint $table) {
            $table->dropUnique(['start_fiscal_year']);
            $table->dropColumn('start_fiscal_year');
        });
    }
};
