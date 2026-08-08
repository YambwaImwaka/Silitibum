<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the broadcast itself, not just the still frame.
 *
 * 2026_08_06_000005 added `snapshot` because nothing of a live survived the
 * room closing — Zego carries the media and discards it at teardown, and Zego's
 * own cloud recording is a paid add-on. The host device records its captured
 * audio/video locally instead and uploads the file when the stream ends, which
 * costs nothing recurring and reuses the storage and HLS pipeline already built
 * for video posts.
 *
 * recording_post_id is the link to the auto-published video post: it is what
 * makes the upload idempotent (a retried finalize returns the existing post
 * rather than publishing the same live twice) and what lets a host deleting
 * that post be understood here as "this live is no longer published".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('livestreams')) {
            if (!Schema::hasColumn('livestreams', 'recording')) {
                DB::statement('ALTER TABLE `livestreams` ADD COLUMN `recording` VARCHAR(500) NULL DEFAULT NULL');
            }
            if (!Schema::hasColumn('livestreams', 'recording_post_id')) {
                DB::statement('ALTER TABLE `livestreams` ADD COLUMN `recording_post_id` BIGINT UNSIGNED NULL DEFAULT NULL');
            }
            if (!Schema::hasColumn('livestreams', 'recording_duration_ms')) {
                DB::statement('ALTER TABLE `livestreams` ADD COLUMN `recording_duration_ms` BIGINT NULL DEFAULT NULL');
            }
            // 0 = none, 1 = uploading, 2 = stored, 3 = failed. Set to 1 by the
            // first chunk so a stream whose upload died mid-way is
            // distinguishable from one that was never recorded at all.
            if (!Schema::hasColumn('livestreams', 'recording_status')) {
                DB::statement('ALTER TABLE `livestreams` ADD COLUMN `recording_status` TINYINT NOT NULL DEFAULT 0');
            }
        }

        if (Schema::hasTable('tbl_settings')) {
            // Kill switch. The whole feature costs the host's upload bandwidth
            // and this account's disk, so it has to be disableable from the
            // admin panel without shipping an app build.
            if (!Schema::hasColumn('tbl_settings', 'livestream_recording_enabled')) {
                DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `livestream_recording_enabled` TINYINT NOT NULL DEFAULT 1');
            }
            // Minutes. A recording is uploaded from a phone, usually on mobile
            // data — an unbounded live would produce a file no host can
            // realistically send, so the device stops recording at this cap and
            // uploads what it has.
            if (!Schema::hasColumn('tbl_settings', 'livestream_recording_max_minutes')) {
                DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `livestream_recording_max_minutes` INT NOT NULL DEFAULT 60');
            }
        }
    }

    public function down(): void
    {
        foreach (['recording', 'recording_post_id', 'recording_duration_ms', 'recording_status'] as $column) {
            if (Schema::hasTable('livestreams') && Schema::hasColumn('livestreams', $column)) {
                DB::statement("ALTER TABLE `livestreams` DROP COLUMN `{$column}`");
            }
        }

        foreach (['livestream_recording_enabled', 'livestream_recording_max_minutes'] as $column) {
            if (Schema::hasTable('tbl_settings') && Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` DROP COLUMN `{$column}`");
            }
        }
    }
};
