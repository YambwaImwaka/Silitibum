<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Still frame kept after a livestream ends.
 *
 * The stream itself is never recorded — Zego carries the media and it is
 * discarded when the room closes. A single captured frame is retained instead,
 * so an ended stream still has something to show in the end screen and in
 * admin analytics without the cost (or the recurring ZegoCloud fee) of storing
 * the video.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('livestreams') && !Schema::hasColumn('livestreams', 'snapshot')) {
            DB::statement('ALTER TABLE `livestreams` ADD COLUMN `snapshot` VARCHAR(500) NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('livestreams') && Schema::hasColumn('livestreams', 'snapshot')) {
            DB::statement('ALTER TABLE `livestreams` DROP COLUMN `snapshot`');
        }
    }
};
