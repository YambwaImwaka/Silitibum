<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete + legal retention for posts.
 *
 * Every delete path was a hard DELETE, and deleteAllPostData() additionally
 * destroyed the likes, comments and — worst of all — the report_posts row that
 * recorded WHY the content was removed. If a takedown is ever disputed, the
 * evidence was already gone.
 *
 * purge_after is the point of the design: the retention sweep becomes one
 * indexed range query instead of recomputing a window per row. Report-driven
 * removals get the longer clock, because that is the case with actual legal
 * exposure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_post')) {
            if (!Schema::hasColumn('tbl_post', 'deleted_at')) {
                DB::statement('ALTER TABLE `tbl_post` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL');
            }
            if (!Schema::hasColumn('tbl_post', 'deleted_by')) {
                DB::statement('ALTER TABLE `tbl_post` ADD COLUMN `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL');
            }
            if (!Schema::hasColumn('tbl_post', 'deletion_reason')) {
                DB::statement('ALTER TABLE `tbl_post` ADD COLUMN `deletion_reason` VARCHAR(255) NULL DEFAULT NULL');
            }
            // 0=author 1=admin 2=moderator 3=report 4=account deletion
            if (!Schema::hasColumn('tbl_post', 'deletion_source')) {
                DB::statement('ALTER TABLE `tbl_post` ADD COLUMN `deletion_source` TINYINT NULL DEFAULT NULL');
            }
            if (!Schema::hasColumn('tbl_post', 'purge_after')) {
                DB::statement('ALTER TABLE `tbl_post` ADD COLUMN `purge_after` DATETIME NULL DEFAULT NULL');
            }

            if (!$this->indexExists('tbl_post', 'idx_post_purge_after')) {
                DB::statement('ALTER TABLE `tbl_post` ADD INDEX `idx_post_purge_after` (`purge_after`)');
            }

            // Every listing query now carries a leading `deleted_at IS NULL`
            // from the SoftDeletes global scope, which makes the existing
            // (post_type, id) / (user_id, id) indexes non-optimal. Build the
            // composites once, here, rather than ALTERing tbl_post again later.
            if (!$this->indexExists('tbl_post', 'idx_post_live_type_id')) {
                DB::statement('ALTER TABLE `tbl_post` ADD INDEX `idx_post_live_type_id` (`deleted_at`, `post_type`, `id`)');
            }
            if (!$this->indexExists('tbl_post', 'idx_post_live_user_id')) {
                DB::statement('ALTER TABLE `tbl_post` ADD INDEX `idx_post_live_user_id` (`deleted_at`, `user_id`, `id`)');
            }
            if (!$this->indexExists('tbl_post', 'idx_post_live_created')) {
                DB::statement('ALTER TABLE `tbl_post` ADD INDEX `idx_post_live_created` (`deleted_at`, `created_at`)');
            }
        }

        if (Schema::hasTable('tbl_settings')) {
            if (!Schema::hasColumn('tbl_settings', 'post_retention_days')) {
                DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `post_retention_days` INT NOT NULL DEFAULT 90');
            }
            if (!Schema::hasColumn('tbl_settings', 'report_retention_days')) {
                DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `report_retention_days` INT NOT NULL DEFAULT 365');
            }
        }
    }

    public function down(): void
    {
        foreach (['idx_post_purge_after', 'idx_post_live_type_id', 'idx_post_live_user_id', 'idx_post_live_created'] as $index) {
            if ($this->indexExists('tbl_post', $index)) {
                DB::statement("ALTER TABLE `tbl_post` DROP INDEX `{$index}`");
            }
        }

        foreach (['deleted_at', 'deleted_by', 'deletion_reason', 'deletion_source', 'purge_after'] as $column) {
            if (Schema::hasTable('tbl_post') && Schema::hasColumn('tbl_post', $column)) {
                DB::statement("ALTER TABLE `tbl_post` DROP COLUMN `{$column}`");
            }
        }

        foreach (['post_retention_days', 'report_retention_days'] as $column) {
            if (Schema::hasTable('tbl_settings') && Schema::hasColumn('tbl_settings', $column)) {
                DB::statement("ALTER TABLE `tbl_settings` DROP COLUMN `{$column}`");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->c > 0;
    }
};
