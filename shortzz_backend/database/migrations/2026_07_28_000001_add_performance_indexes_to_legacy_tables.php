<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The legacy vendor tables (everything from shortzz_database.sql) ship with
// PRIMARY KEY (`id`) and nothing else — not one secondary index. Every foreign
// key the app filters on is unindexed, so MySQL full-scans the table for
// queries that run on literally every screen:
//
//   user_auth_tokens.auth_token  — scanned once per AUTHENTICATED REQUEST
//                                  (GlobalFunction::getUserFromAuthToken),
//                                  so this one index alone touches 100% of
//                                  API traffic
//   tbl_likes(post_id,user_id)   — "did I like this post", per post per feed
//   tbl_followers.*              — follower/following counts and lists
//   tbl_post.user_id             — every profile grid
//   tbl_comments.post_id         — every comment sheet open
//
// At the current ~240 posts this is invisible; cost grows linearly with row
// count, so it degrades exactly as the userbase grows. Adding these before the
// tables are large is the cheap moment to do it — ALTER TABLE rebuilds the
// table, which is instant now and an outage later.
//
// Deliberately plain (non-unique) indexes: several of these pairs may already
// contain duplicate rows from before the app enforced uniqueness, and a UNIQUE
// index would abort the migration. Dedupe separately if you want constraints.
//
// Idempotent — re-running skips indexes that already exist, so this is safe to
// apply to a database that was partially hand-tuned.
return new class extends Migration
{
    /**
     * column list keyed by table => [index name => columns]
     */
    private function indexes(): array
    {
        return [
            'user_auth_tokens' => [
                // Highest-impact index in the schema: hit on every authed call.
                'idx_uat_token' => ['auth_token(191)'],
                'idx_uat_user' => ['user_id'],
            ],
            'tbl_post' => [
                'idx_post_user' => ['user_id'],
                // Feeds filter `whereIn(post_type)` then order/paginate by id.
                'idx_post_type_id' => ['post_type', 'id'],
                'idx_post_user_id' => ['user_id', 'id'],
                'idx_post_sound' => ['sound_id'],
                'idx_post_trending' => ['is_trending'],
                'idx_post_created' => ['created_at'],
            ],
            'tbl_followers' => [
                'idx_fol_from' => ['from_user_id'],
                'idx_fol_to' => ['to_user_id'],
                // Covers the "am I following X" existence check.
                'idx_fol_pair' => ['from_user_id', 'to_user_id'],
            ],
            'tbl_likes' => [
                'idx_like_post' => ['post_id'],
                'idx_like_user' => ['user_id'],
                'idx_like_pair' => ['post_id', 'user_id'],
            ],
            'tbl_comments' => [
                'idx_cmt_post' => ['post_id'],
                'idx_cmt_user' => ['user_id'],
                'idx_cmt_post_id' => ['post_id', 'id'],
            ],
            'comment_likes' => [
                'idx_cl_comment' => ['comment_id'],
                'idx_cl_pair' => ['comment_id', 'user_id'],
            ],
            'comment_replies' => [
                'idx_cr_comment' => ['comment_id'],
                'idx_cr_user' => ['user_id'],
            ],
            'post_saves' => [
                'idx_ps_user' => ['user_id'],
                'idx_ps_pair' => ['user_id', 'post_id'],
            ],
            'post_images' => [
                'idx_pi_post' => ['post_id'],
            ],
            'tbl_user_block' => [
                'idx_blk_from' => ['from_user_id'],
                'idx_blk_to' => ['to_user_id'],
            ],
            'stories' => [
                // Story trays load "recent stories by the people I follow".
                'idx_story_user_created' => ['user_id', 'created_at'],
                'idx_story_created' => ['created_at'],
            ],
            'notification_users' => [
                'idx_nu_to_created' => ['to_user_id', 'created_at'],
            ],
            'tbl_hash_tags' => [
                // varchar(100), so no prefix length (a prefix longer than the
                // column is MySQL error 1089). Only `auth_token` needs one,
                // because it is a TEXT column.
                'idx_ht_tag' => ['hashtag'],
                'idx_ht_explore' => ['on_explore'],
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
                $cols = implode(', ', array_map(fn ($c) => $this->quoteColumn($c), $columns));
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

    // Accepts "col" or "col(191)" (prefix length, required for TEXT columns)
    // and emits `col` / `col`(191).
    private function quoteColumn(string $column): string
    {
        if (preg_match('/^([a-z0-9_]+)\((\d+)\)$/i', $column, $m)) {
            return "`{$m[1]}`({$m[2]})";
        }
        return "`{$column}`";
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
};
