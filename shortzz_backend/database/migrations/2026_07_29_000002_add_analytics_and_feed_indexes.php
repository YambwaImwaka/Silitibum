<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Indexes for two things added at the same time:
//
//  1. The new dashboard analytics endpoints, which all group by DATE(created_at)
//     over a one-month range. Without an index on created_at each chart is a
//     full scan of the underlying table per request.
//     NOTE: the range predicate uses created_at directly (WHERE created_at
//     BETWEEN ...), which is index-usable; the DATE() call is only in the
//     SELECT/GROUP BY, so wrapping it does not disqualify the index here.
//
//  2. FeedRankingService's location signal, which compares the viewer's
//     country/region against the candidate authors' — read on every For You
//     fetch.
//
// Same idempotent tableExists/indexExists shape as
// 2026_07_28_000001_add_performance_indexes_to_legacy_tables, so this is safe to
// re-run and safe on a database where some of these were added by hand.
return new class extends Migration
{
    /**
     * column list keyed by table => [index name => columns]
     */
    private function indexes(): array
    {
        return [
            // --- For You ranking -------------------------------------------
            'tbl_users' => [
                // FeedRankingService::locationScore compares author vs viewer
                // location. Not covered by the earlier perf-index migration.
                'idx_users_country_region' => ['country', 'region'],
            ],
            'tbl_post' => [
                // The recent segment of the candidate pool
                // (created_at >= now-14d, ORDER BY id DESC) and the dashboard's
                // posts-per-day series.
                'idx_post_type_created' => ['post_type', 'created_at'],
            ],

            // --- Dashboard engagement chart --------------------------------
            'tbl_likes' => [
                'idx_likes_created' => ['created_at'],
            ],
            'tbl_comments' => [
                'idx_comments_created' => ['created_at'],
            ],
            'post_saves' => [
                'idx_saves_created' => ['created_at'],
            ],
            'tbl_followers' => [
                'idx_followers_created' => ['created_at'],
            ],

            // --- Dashboard revenue chart -----------------------------------
            'tbl_coin_topup_request' => [
                'idx_topup_status_created' => ['status', 'created_at'],
            ],
            'tbl_redeem_request' => [
                'idx_redeem_status_created' => ['status', 'created_at'],
            ],

            // --- Livestream analytics --------------------------------------
            'livestreams' => [
                'idx_ls_created' => ['created_at'],
                'idx_ls_status' => ['status'],
            ],
            'livestream_users' => [
                'idx_lsu_user' => ['user_id'],
                'idx_lsu_created' => ['created_at'],
                // Top-hosts aggregation filters on type then sums live_coin.
                'idx_lsu_type' => ['type'],
            ],
            'livestream_comments' => [
                'idx_lsc_type_created' => ['comment_type', 'created_at'],
                'idx_lsc_gift' => ['gift_id'],
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($indexes as $name => $columns) {
                if ($this->indexExists($table, $name)) {
                    continue;
                }
                // Skip an index whose columns don't all exist — livestream
                // tables were added by migration, legacy ones by SQL dump, and
                // a half-applied environment should not abort the whole run.
                if (!$this->columnsExist($table, $columns)) {
                    continue;
                }
                $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols})");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes() as $table => $indexes) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach (array_keys($indexes) as $name) {
                if ($this->indexExists($table, $name)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                }
            }
        }
    }

    private function tableExists(string $table): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->c > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->c > 0;
    }

    private function columnsExist(string $table, array $columns): bool
    {
        $found = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND COLUMN_NAME IN (' . implode(',', array_fill(0, count($columns), '?')) . ')',
            array_merge([$table], $columns)
        )->c;

        return (int) $found === count($columns);
    }
};
