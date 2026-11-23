<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backup log (TOR 4.4-5, คุณลักษณะ 2.1-5: "สำรองข้อมูลทุก 1 เดือนเป็นอย่างน้อย", checked by showing this log).
     * One row per run, manual (admin button) or scheduled (cron, if the host has it). The file is in
     * storage/app/private/backups; file_deleted_at is set when the retention rule removes an old one.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // database | files
            $table->string('trigger', 16); // manual | scheduled
            $table->string('status', 16); // running | success | failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('message', 1000)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Nullable on purpose: a NOT NULL timestamp without a default becomes "ON UPDATE CURRENT_TIMESTAMP" on
            // MariaDB/MySQL with explicit_defaults_for_timestamp off (XAMPP, many shared hosts), which rewrote the
            // start time (in server-local time) when the run was marked finished.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('file_deleted_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
