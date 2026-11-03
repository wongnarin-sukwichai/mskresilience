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
        // Cancelling an import (owner decision 2026-11-03): the batch row stays as history, marked cancelled with
        // who/when. The original file is kept (files are kept forever).
        Schema::table('import_batches', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('community_count');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });

        // The values a batch overwrote, so cancelling it can put them back (owner decision 2026-11-03). One row per
        // data record the batch replaced. `previous` holds that record's value columns as they were, and
        // previous_batch_id the import they came from (null if they had none).
        Schema::create('import_replaced_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('record_id');
            $table->foreignId('previous_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->json('previous');
            $table->timestamps();

            $table->index(['import_batch_id', 'record_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_replaced_rows');

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
