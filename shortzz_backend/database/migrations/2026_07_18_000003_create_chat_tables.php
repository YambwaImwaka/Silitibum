<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 1:1 chat in MySQL (replaces Firestore users_list/chats collections).
// A thread's user pair is stored ordered (user1_id < user2_id) so the pair is
// unique; per-side state (unread, read cursor, cleared cursor) lives in
// user1_*/user2_* columns. last_msg_at is epoch milliseconds for cheap
// client-side ordering and cursor pagination.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user1_id');
            $table->unsignedBigInteger('user2_id');
            $table->unsignedBigInteger('initiator_id');
            $table->enum('status', ['request', 'approved'])->default('request');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->string('last_msg', 255)->nullable();
            $table->string('last_msg_type', 20)->nullable();
            $table->unsignedBigInteger('last_msg_user_id')->nullable();
            $table->unsignedBigInteger('last_msg_at')->nullable();
            $table->unsignedInteger('user1_unread_count')->default(0);
            $table->unsignedInteger('user2_unread_count')->default(0);
            $table->unsignedBigInteger('user1_last_read_message_id')->default(0);
            $table->unsignedBigInteger('user2_last_read_message_id')->default(0);
            $table->unsignedBigInteger('user1_cleared_before_id')->default(0);
            $table->unsignedBigInteger('user2_cleared_before_id')->default(0);
            $table->timestamps();
            $table->unique(['user1_id', 'user2_id'], 'uq_thread_pair');
            $table->index(['user1_id', 'last_msg_at'], 'idx_threads_u1');
            $table->index(['user2_id', 'last_msg_at'], 'idx_threads_u2');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('thread_id');
            $table->unsignedBigInteger('sender_id');
            $table->string('message_type', 20); // text|image|video|audio|post|story_reply
            $table->text('text_message')->nullable();
            $table->string('image_message', 500)->nullable();
            $table->string('video_message', 500)->nullable();
            $table->string('audio_message', 500)->nullable();
            $table->text('wave_data')->nullable();
            $table->text('post_message')->nullable();        // JSON blob (post share)
            $table->text('story_reply_message')->nullable(); // JSON blob
            $table->boolean('is_unsent')->default(false);
            $table->boolean('deleted_by_user1')->default(false);
            $table->boolean('deleted_by_user2')->default(false);
            $table->timestamps();
            $table->index(['thread_id', 'id'], 'idx_msgs_thread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};
