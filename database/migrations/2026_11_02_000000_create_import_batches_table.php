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
        // Import history (owner decision 2026-11-02): there is no admin review step, so every confirmed import is
        // recorded with who/when/which file. The original file is kept in private storage (stored_path).
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('work_group');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('replaced_count');
            $table->unsignedInteger('community_count');
            $table->timestamps();

            $table->index(['work_group', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
