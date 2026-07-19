<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Email verification + password-reset codes (replaces Firebase email
// verification / password-reset mails). One active code per (user, type);
// older codes are consumed when a new one is issued.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('email', 191);
            $table->string('code', 6);
            $table->enum('type', ['verify_email', 'reset_password']);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'type'], 'idx_vc_user_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
