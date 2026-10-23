<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a live room's host was last seen alive (LivestreamLiveness).
 *
 * A host whose app crashed, was killed or lost signal never calls
 * endLivestream, so the row stayed `live` — production had rooms "live" since
 * July — and the Live tab listed rooms with nobody broadcasting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('livestreams') || Schema::hasColumn('livestreams', 'last_seen_at')) {
            return;
        }
        DB::statement('ALTER TABLE `livestreams` ADD COLUMN `last_seen_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`');
        // fetchLivestreams and the reaper both filter on exactly this pair.
        DB::statement('ALTER TABLE `livestreams` ADD INDEX `livestreams_status_last_seen_index` (`status`, `last_seen_at`)');
    }

    public function down(): void
    {
        if (!Schema::hasTable('livestreams') || !Schema::hasColumn('livestreams', 'last_seen_at')) {
            return;
        }
        DB::statement('ALTER TABLE `livestreams` DROP INDEX `livestreams_status_last_seen_index`');
        DB::statement('ALTER TABLE `livestreams` DROP COLUMN `last_seen_at`');
    }
};
