<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ax_exams', function (Blueprint $table) {
            $table->id();
            $table->string('public_code', 16)->unique();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('ax_groups')->nullOnDelete();
            $table->enum('mode', ['quiz', 'survey'])->default('survey');
            $table->enum('status', ['draft', 'scheduled', 'open', 'closed', 'archived'])->default('draft')->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->enum('display_mode', ['single_page', 'stepwise'])->default('single_page');
            $table->enum('show_result_to_voter', ['none', 'score', 'score_and_answers'])->default('none');
            $table->enum('results_visibility', ['after_close', 'live'])->default('after_close');
            $table->unsignedSmallInteger('min_responses_to_show')->default(5);
            $table->boolean('announce_on_open')->default(true);
            $table->boolean('announce_on_close')->default(true);
            $table->json('reminders')->nullable();
            $table->json('reminders_sent')->nullable();
            $table->bigInteger('announce_message_id')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_admin_id')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ax_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('ax_exams')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->enum('type', ['single', 'multi', 'true_false', 'short_text', 'long_text', 'rating', 'number']);
            $table->text('text');
            $table->text('help_text')->nullable();
            $table->boolean('is_required')->default(true);
            $table->decimal('points', 8, 2)->default(0);
            $table->json('config')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->index(['exam_id', 'position']);
        });

        Schema::create('ax_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('ax_questions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('text', 1000);
            $table->boolean('is_correct')->default(false);
            $table->index(['question_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ax_options');
        Schema::dropIfExists('ax_questions');
        Schema::dropIfExists('ax_exams');
    }
};
