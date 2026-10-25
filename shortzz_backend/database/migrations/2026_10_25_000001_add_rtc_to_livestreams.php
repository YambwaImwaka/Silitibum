<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which media service a live room broadcasts on. Apps from 1.2.3 go live on
 * LiveKit and say so; older apps still try Zego, whose account has lapsed,
 * so their rooms never carry video. New apps list only LiveKit rooms rather
 * than showing those as "connecting" forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('livestreams', 'rtc')) {
            DB::statement("ALTER TABLE `livestreams` ADD COLUMN `rtc` VARCHAR(16) NOT NULL DEFAULT 'zego' AFTER `type`");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('livestreams', 'rtc')) {
            DB::statement("ALTER TABLE `livestreams` DROP COLUMN `rtc`");
        }
    }
};
