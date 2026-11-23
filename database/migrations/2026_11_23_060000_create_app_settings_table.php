<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System-wide settings an admin changes on ตั้งค่าทั่วไป (App\Support\Settings holds the keys and defaults).
     * A key with no row uses its default.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            // A numeric id because the audit trail (activity_log.subject_id) points at rows by integer id.
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
