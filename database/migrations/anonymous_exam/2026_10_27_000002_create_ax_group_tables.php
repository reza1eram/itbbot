<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ax_groups', function (Blueprint $table) {
            $table->id();
            // Bale chat ids can exceed 32 bits and are usually negative for groups
            $table->bigInteger('chat_id')->unique();
            $table->string('title', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('members_count')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ax_registration_codes', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->foreignId('group_id')->nullable()->constrained('ax_groups')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        // Only for BALE_MEMBERSHIP_MODE=manual: HMAC(user id, group) – raw ids are never stored
        Schema::create('ax_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('ax_groups')->cascadeOnDelete();
            $table->char('member_hash', 64);
            $table->unique(['group_id', 'member_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ax_group_members');
        Schema::dropIfExists('ax_registration_codes');
        Schema::dropIfExists('ax_groups');
    }
};
