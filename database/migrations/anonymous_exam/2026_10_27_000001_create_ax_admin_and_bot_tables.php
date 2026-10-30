<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ax_admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('username', 60)->unique();
            $table->string('password');
            $table->enum('role', ['owner', 'editor', 'viewer'])->default('editor');
            $table->boolean('is_active')->default(true);
            // Optional Bale chat id of an admin, only used for admin bot commands (/myexams, /close, /results)
            $table->unsignedBigInteger('bale_admin_chat_id')->nullable()->unique();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ax_bots', function (Blueprint $table) {
            $table->id();
            // Optional link to the repo-wide `bots` table record (no FK: the legacy table may not exist)
            $table->unsignedBigInteger('bot_id')->nullable();
            $table->string('username', 64)->unique();
            $table->enum('update_mode', ['polling', 'webhook'])->default('polling');
            $table->enum('status', ['unknown', 'ok', 'error'])->default('unknown');
            $table->json('get_me')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ax_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
        });

        Schema::create('ax_message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('ax_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable()->index();
            $table->string('action', 60)->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        // Dedupe of incoming Bale updates; holds nothing but the numeric update id
        Schema::create('ax_processed_updates', function (Blueprint $table) {
            $table->unsignedBigInteger('update_id')->primary();
            $table->date('day')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ax_processed_updates');
        Schema::dropIfExists('ax_audit_logs');
        Schema::dropIfExists('ax_message_templates');
        Schema::dropIfExists('ax_settings');
        Schema::dropIfExists('ax_bots');
        Schema::dropIfExists('ax_admin_users');
    }
};
