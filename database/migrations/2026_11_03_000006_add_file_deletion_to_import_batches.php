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
        // Admin may delete kept original files to free disk space, from any batch (owner decision 2026-11-03).
        // Only the file goes: the imported data and the history row stay. Who/when and the freed size are kept,
        // so a deleted file isn't reported as "missing".
        Schema::table('import_batches', function (Blueprint $table) {
            $table->timestamp('file_deleted_at')->nullable()->after('cancelled_by');
            $table->foreignId('file_deleted_by')->nullable()->after('file_deleted_at')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('deleted_file_bytes')->nullable()->after('file_deleted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('file_deleted_by');
            $table->dropColumn(['file_deleted_at', 'deleted_file_bytes']);
        });
    }
};
