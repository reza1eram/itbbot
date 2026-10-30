<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two "worlds" of the anonymity model (docs/PRIVACY-MODEL.md):
 *
 *  identity world: ax_participations, ax_access_tokens
 *  response world: ax_responses, ax_answers
 *
 * There is deliberately NO foreign key, shared id or common column between the two worlds.
 * No table here has automatic timestamps; times are coarse (day / hour) on purpose.
 */
return new class extends Migration {
    public function up(): void
    {
        // ---------- identity world ----------
        Schema::create('ax_participations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('exam_id')->constrained('ax_exams')->cascadeOnDelete();
            $table->char('voter_hash', 64);
            $table->enum('state', ['token_issued', 'started', 'submitted'])->default('token_issued');
            $table->date('day');
            $table->unique(['exam_id', 'voter_hash']);
            $table->index(['exam_id', 'state']);
        });

        Schema::create('ax_access_tokens', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->uuid('participation_id')->index();
            $table->foreignId('exam_id')->constrained('ax_exams')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });

        // ---------- response world ----------
        Schema::create('ax_responses', function (Blueprint $table) {
            // Random UUID v4 (not auto-increment, not time-ordered ULID/UUIDv7)
            $table->uuid('id')->primary();
            $table->foreignId('exam_id')->constrained('ax_exams')->cascadeOnDelete();
            $table->dateTime('submitted_hour');
            $table->decimal('score', 10, 2)->nullable();
            $table->decimal('max_score', 10, 2)->nullable();
            $table->index('exam_id');
        });

        Schema::create('ax_answers', function (Blueprint $table) {
            // No auto-increment id: an increasing id would leak submission order
            $table->uuid('response_id');
            $table->unsignedBigInteger('question_id');
            $table->json('option_ids')->nullable();
            $table->text('text_value')->nullable();
            $table->decimal('number_value', 14, 4)->nullable();
            $table->decimal('points_awarded', 10, 2)->nullable();
            $table->primary(['response_id', 'question_id']);
            $table->index('question_id');
            $table->foreign('response_id')->references('id')->on('ax_responses')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on('ax_questions')->cascadeOnDelete();
        });

        // Optional phase-2 hardening: encrypted pool flushed in random batches
        Schema::create('ax_response_pool', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('exam_id')->index();
            $table->longText('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ax_response_pool');
        Schema::dropIfExists('ax_answers');
        Schema::dropIfExists('ax_responses');
        Schema::dropIfExists('ax_access_tokens');
        Schema::dropIfExists('ax_participations');
    }
};
