<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the retention policy from posts to comments and replies.
 *
 * "Deleted on the user side, kept on the admin side" has to cover comments
 * too: a defamatory or abusive comment is exactly the kind of content a
 * takedown gets disputed over, and hard-deleting it destroyed the only record
 * of what was actually said.
 *
 * Same shape as tbl_post so the admin trash view and the retention sweep can
 * treat all three uniformly.
 */
return new class extends Migration
{
    /** table => whether it needs the full attribution set */
    private const TABLES = ['tbl_comments', 'comment_replies'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            if (!Schema::hasColumn($table, 'deleted_at')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL");
            }
            if (!Schema::hasColumn($table, 'deleted_by')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `deleted_by` BIGINT UNSIGNED NULL DEFAULT NULL");
            }
            // Mirrors GlobalFunction::DELETED_BY_* (0=author 1=admin 2=moderator)
            if (!Schema::hasColumn($table, 'deletion_source')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `deletion_source` TINYINT NULL DEFAULT NULL");
            }
            if (!Schema::hasColumn($table, 'purge_after')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `purge_after` DATETIME NULL DEFAULT NULL");
            }

            // Every comment listing now carries a leading `deleted_at IS NULL`
            // from the SoftDeletes global scope.
            $liveIndex = 'idx_' . $table . '_live';
            if (!$this->indexExists($table, $liveIndex)) {
                $parent = $table === 'tbl_comments' ? 'post_id' : 'comment_id';
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$liveIndex}` (`deleted_at`, `{$parent}`, `id`)");
            }

            $purgeIndex = 'idx_' . $table . '_purge_after';
            if (!$this->indexExists($table, $purgeIndex)) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$purgeIndex}` (`purge_after`)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach (['idx_' . $table . '_live', 'idx_' . $table . '_purge_after'] as $index) {
                if ($this->indexExists($table, $index)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                }
            }

            foreach (['deleted_at', 'deleted_by', 'deletion_source', 'purge_after'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    DB::statement("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
                }
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
