<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MySQL-native auth (Firebase Auth removal). tbl_users.password already exists
// (dummy users store a display password there); real users now store a bcrypt
// hash in the same column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_users', function (Blueprint $table) {
            $table->dateTime('email_verified_at')->nullable();
            $table->string('provider_uid', 191)->nullable()->index('idx_users_provider_uid');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_users', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'provider_uid']);
        });
    }
};
