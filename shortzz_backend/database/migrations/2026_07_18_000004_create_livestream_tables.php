<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Livestream signalling in MySQL (replaces the Firestore livestreams tree).
// Media stays on Zego; these tables carry room state, participants and
// comments/gifts. Enum strings match the Flutter models exactly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('livestreams', function (Blueprint $table) {
            $table->id();
            $table->string('room_id', 64)->unique();
            $table->unsignedBigInteger('host_id');
            $table->string('description', 255)->nullable();
            $table->string('type', 20)->default('LIVESTREAM'); // LIVESTREAM|BATTLE
            $table->string('battle_type', 20)->default('INITIATE'); // INITIATE|WAITING|RUNNING|END
            $table->integer('battle_duration')->default(5);
            $table->unsignedBigInteger('battle_created_at')->nullable(); // epoch ms
            $table->boolean('is_restrict_to_join')->default(false);
            $table->integer('host_view_id')->nullable();
            $table->unsignedInteger('like_count')->default(0);
            $table->unsignedInteger('watching_count')->default(0);
            $table->text('co_host_ids')->nullable(); // JSON int array
            $table->string('status', 10)->default('live'); // live|ended
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at'], 'idx_ls_status');
        });

        Schema::create('livestream_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('livestream_id');
            $table->unsignedBigInteger('user_id');
            $table->string('type', 12)->default('AUDIENCE'); // HOST|CO-HOST|AUDIENCE|REQUESTED|INVITED|LEFT
            $table->string('audio_status', 12)->default('ON'); // ON|OFF_BY_ME|OFF_BY_HOST
            $table->string('video_status', 12)->default('ON');
            $table->integer('live_coin')->default(0);
            $table->integer('current_battle_coin')->default(0);
            $table->integer('total_battle_coin')->default(0);
            $table->text('followers_gained')->nullable(); // JSON int array
            $table->unsignedBigInteger('join_stream_time')->nullable(); // epoch ms
            $table->dateTime('left_at')->nullable();
            $table->timestamps();
            $table->unique(['livestream_id', 'user_id'], 'uq_stream_user');
        });

        Schema::create('livestream_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('livestream_id');
            $table->unsignedBigInteger('sender_id');
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->string('comment_type', 20)->default('TEXT'); // TEXT|GIFT|REQUEST|JOINED|JOINED_CO_HOST
            $table->text('comment')->nullable();
            $table->unsignedBigInteger('gift_id')->nullable();
            $table->timestamps();
            $table->index(['livestream_id', 'id'], 'idx_lc_stream');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livestream_comments');
        Schema::dropIfExists('livestream_users');
        Schema::dropIfExists('livestreams');
    }
};
