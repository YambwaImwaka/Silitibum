<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recomputes the trending ranking.
 *
 * Scores WINDOWED engagement pulled from the child tables rather than
 * tbl_post.likes/comments/shares. Those columns are lifetime totals, so
 * ranking on them just re-surfaces the same all-time canon the Explore page
 * already shows — the opposite of "trending".
 *
 * Runs hourly rather than per request: the query below joins four correlated
 * aggregates, which is far too expensive to run on every Explore open.
 */
class ComputeTrendingPosts extends Command
{
    protected $signature = 'posts:compute-trending
                            {--window=72 : Hours of engagement to score}
                            {--keep=1000 : How many ranked posts to retain}';

    protected $description = 'Recompute the trending post ranking';

    /** Posts flagged is_trending for the feed ranker. */
    private const TRENDING_FLAG_COUNT = 200;

    public function handle(): int
    {
        if (!Schema::hasTable('post_trending_scores')) {
            $this->error('post_trending_scores table missing; run migrations first.');
            return self::FAILURE;
        }

        $windowHours = max(1, (int) $this->option('window'));
        $keep = max(1, (int) $this->option('keep'));
        $since = Carbon::now()->subHours($windowHours);
        // Only score reasonably fresh posts; an old post picking up a few
        // likes should not outrank genuinely active content.
        $postSince = Carbon::now()->subDays(30);

        $hasViews = Schema::hasTable('post_views');

        $viewJoin = $hasViews
            ? "LEFT JOIN (SELECT post_id, COUNT(*) c FROM post_views WHERE created_at >= ? GROUP BY post_id) v ON v.post_id = p.id"
            : '';
        $viewTerm = $hasViews ? 'COALESCE(v.c,0) * 0.05' : '0';

        $bindings = [$since, $since, $since];
        if ($hasViews) {
            $bindings[] = $since;
        }
        $bindings[] = $postSince;

        $sql = "
            SELECT p.id AS post_id,
                   p.post_type,
                   (COALESCE(l.c,0) * 1
                    + COALESCE(c.c,0) * 2
                    + COALESCE(s.c,0) * 2
                    + {$viewTerm}) AS trend_score
            FROM tbl_post p
            LEFT JOIN (SELECT post_id, COUNT(*) c FROM tbl_likes    WHERE created_at >= ? GROUP BY post_id) l ON l.post_id = p.id
            LEFT JOIN (SELECT post_id, COUNT(*) c FROM tbl_comments WHERE created_at >= ? GROUP BY post_id) c ON c.post_id = p.id
            LEFT JOIN (SELECT post_id, COUNT(*) c FROM post_saves   WHERE created_at >= ? GROUP BY post_id) s ON s.post_id = p.id
            {$viewJoin}
            WHERE p.deleted_at IS NULL
              AND p.created_at >= ?
            HAVING trend_score > 0
            ORDER BY trend_score DESC, p.id DESC
            LIMIT {$keep}
        ";

        $rows = DB::select($sql, $bindings);

        if (count($rows) === 0) {
            $this->info('No engagement in the window; leaving the previous ranking in place.');
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $records = [];
        $rank = 0;
        foreach ($rows as $row) {
            $rank++;
            $records[] = [
                'post_id' => $row->post_id,
                'score' => $row->trend_score,
                'rank_position' => $rank,
                'post_type' => $row->post_type,
                'computed_at' => $now,
            ];
        }

        DB::transaction(function () use ($records, $keep) {
            // Replace wholesale: a stale row left behind would keep a post in
            // the grid long after it stopped trending.
            //
            // delete(), NOT truncate(): TRUNCATE is DDL and forces an implicit
            // commit in MySQL, which ends this transaction mid-flight and makes
            // the commit below fail with "There is no active transaction" —
            // leaving the table empty and the ranking gone. The table is capped
            // at $keep rows, so a DELETE is cheap.
            DB::table('post_trending_scores')->delete();
            foreach (array_chunk($records, 200) as $chunk) {
                DB::table('post_trending_scores')->insert($chunk);
            }
            DB::table('post_trending_scores')->where('rank_position', '>', $keep)->delete();
        });

        $this->flagTrendingPosts($records);

        $this->info('Ranked ' . count($records) . ' post(s).');

        return self::SUCCESS;
    }

    /**
     * Writes tbl_post.is_trending — a column that exists in the schema and was
     * never written by any code, which is why FeedRankingService's trending
     * weight has been inert since it was added.
     */
    private function flagTrendingPosts(array $records): void
    {
        $topIds = array_column(array_slice($records, 0, self::TRENDING_FLAG_COUNT), 'post_id');

        DB::table('tbl_post')->where('is_trending', 1)
            ->when(count($topIds) > 0, fn ($q) => $q->whereNotIn('id', $topIds))
            ->update(['is_trending' => 0]);

        if (count($topIds) > 0) {
            foreach (array_chunk($topIds, 500) as $chunk) {
                DB::table('tbl_post')->whereIn('id', $chunk)->update(['is_trending' => 1]);
            }
        }
    }
}
