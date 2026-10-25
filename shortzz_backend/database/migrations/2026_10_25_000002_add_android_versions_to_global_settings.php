<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Android build numbers (version codes, e.g. 28) the app compares its own
 * with, set in admin Settings once a release is live on Play. Below the
 * latest, the app offers an update; below the minimum, it requires one.
 * 0 turns either off. Apps from 1.3.0 (28) read them; older apps cannot,
 * and are reached by a push instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tbl_settings', 'android_latest_version')) {
            DB::statement("ALTER TABLE `tbl_settings`"
                . " ADD COLUMN `android_latest_version` INT NOT NULL DEFAULT 0,"
                . " ADD COLUMN `android_min_version` INT NOT NULL DEFAULT 0");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tbl_settings', 'android_latest_version')) {
            DB::statement("ALTER TABLE `tbl_settings`"
                . " DROP COLUMN `android_latest_version`,"
                . " DROP COLUMN `android_min_version`");
        }
    }
};
