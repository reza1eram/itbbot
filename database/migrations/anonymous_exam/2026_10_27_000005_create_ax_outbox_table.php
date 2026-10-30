<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Outgoing GROUP messages only. Private messages that carry access links are never stored.
        Schema::create('ax_outbox', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id');
            $table->unsignedBigInteger('exam_id')->nullable()->index();
            $table->string('purpose', 40)->nullable();
            $table->text('text');
            $table->json('reply_markup')->nullable();
            $table->enum('status', ['pending', 'sending', 'sent', 'failed'])->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->bigInteger('sent_message_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ax_outbox');
    }
};
