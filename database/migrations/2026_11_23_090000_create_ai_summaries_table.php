<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executive Summary texts written by AI (TOR 4.1.1(3)-4), one row per call: the facts sent, the text back, and
     * the tokens/cost, so the cost can be checked (owner 2026-11-23: an info button and a usage log). A text is shown
     * only while its facts_hash matches the current data, and only if every number in it is in the facts.
     */
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('facts_hash', 64)->index();
            $table->json('facts');
            $table->text('text')->nullable();
            $table->string('status', 24); // ok | rejected_numbers | error
            $table->string('error', 1000)->nullable();
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 5)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
