<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unique view + share tracking.
 *
 * tbl_post.views was a bare counter incremented on every playback by a public,
 * unauthenticated endpoint — the same viewer re-swiping onto a reel counted
 * again, and a script could inflate it without a token. Since `views` feeds
 * FeedRankingService's engagement and popularity signals, that was directly
 * gameable.
 *
 * Dedupe is per viewer PER DAY rather than lifetime. Lifetime-unique would
 * freeze the number — a reel rewatched next week would never count again — and
 * would leave no time-bucketed rows for the trending window to aggregate.
 * "Unique viewers per day" is the standard definition; the consequence is that
 * tbl_post.views now means unique viewer-DAYS, not unique viewers.
 *
 * The counter on tbl_post is NOT written here. Doing so per view took an
 * exclusive lock on a single row, serialising every concurrent viewer of a
 * viral reel; posts:rollup-views applies batched deltas instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('post_views')) {
            Schema::create('post_views', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('post_id');
                // 0 = guest. The endpoint is deliberately open so signed-out
                // watching still counts.
                $table->unsignedBigInteger('user_id')->default(0);
                // sha1 of the account id, or of a salted device fingerprint
                // for guests — never a plaintext IP.
                $table->char('viewer_key', 40);
                $table->date('view_date');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['post_id', 'viewer_key', 'view_date'], 'uniq_pv_post_viewer_day');
                $table->index(['post_id', 'created_at'], 'idx_pv_post_created');
                $table->index('created_at', 'idx_pv_created');
            });
        }

        if (!Schema::hasTable('post_shares')) {
            Schema::create('post_shares', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('post_id');
                $table->unsignedBigInteger('user_id')->default(0);
                $table->char('viewer_key', 40);
                $table->date('view_date');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['post_id', 'viewer_key', 'view_date'], 'uniq_ps_post_viewer_day');
                $table->index(['post_id', 'created_at'], 'idx_ps_post_created');
                $table->index('created_at', 'idx_ps_created');
            });
        }

        // Watermark for the rollup command. Hidden from API payloads in the
        // GlobalSettings model — the app has no business reading it.
        if (Schema::hasTable('tbl_settings') && !Schema::hasColumn('tbl_settings', 'views_rollup_cursor')) {
            DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `views_rollup_cursor` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
        if (Schema::hasTable('tbl_settings') && !Schema::hasColumn('tbl_settings', 'shares_rollup_cursor')) {
            DB::statement('ALTER TABLE `tbl_settings` ADD COLUMN `shares_rollup_cursor` BIGINT UNSIGNED NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_views');
        Schema::dropIfExists('post_shares');

        if (Schema::hasTable('tbl_settings') && Schema::hasColumn('tbl_settings', 'views_rollup_cursor')) {
            DB::statement('ALTER TABLE `tbl_settings` DROP COLUMN `views_rollup_cursor`');
        }
        if (Schema::hasTable('tbl_settings') && Schema::hasColumn('tbl_settings', 'shares_rollup_cursor')) {
            DB::statement('ALTER TABLE `tbl_settings` DROP COLUMN `shares_rollup_cursor`');
        }
    }
};
