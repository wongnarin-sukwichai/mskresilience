<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two things become import batches besides an uploaded file (TOR rework, 2026-11-23):
     * - source: 'file' (upload), or a change made in the web edit form: 'web_add', 'web_edit', 'web_delete'
     *   (TOR 4.1.1(6)-2). A web change has no file; its row is kept in payload.
     * - status: 'active' (the data is in the tables), 'pending' (waiting for an admin, when approval mode is on;
     *   nothing is saved yet, TOR 4.1.1(6)-1) or 'rejected'. A cancelled batch keeps using cancelled_at.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('source', 16)->default('file')->after('work_group');
            $table->string('status', 16)->default('active')->after('source');
            $table->json('payload')->nullable()->after('community_count');
            $table->foreignId('reviewed_by')->nullable()->after('payload')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 500)->nullable()->after('reviewed_at');
            $table->string('original_filename')->nullable()->change();
            $table->string('stored_path')->nullable()->change();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['source', 'status', 'payload', 'reviewed_at', 'review_note']);
        });
    }
};
