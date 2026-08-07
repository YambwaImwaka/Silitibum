<?php

namespace App\Console\Commands;

use App\Models\GlobalSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Folds deduplicated post_views / post_shares rows into the tbl_post counters.
 *
 * increaseViewsCount used to do `$post->increment('views')` inline, taking an
 * exclusive lock on one tbl_post row per event — every concurrent viewer of a
 * popular reel serialised behind that lock. Writing an indexed row per view
 * and batching the counter update here collapses N row locks into one lock per
 * post per run.
 *
 * Applies DELTAS from a watermark cursor rather than recomputing totals, so
 * existing `views` values are preserved: there is no backfill to run and no
 * visible dip in the feed the moment this ships. The cursor is persisted only
 * after the batch commits, so a crash mid-run replays rather than skips (at
 * worst a batch is applied twice — see the transaction below, which makes
 * that impossible in practice).
 */
class RollupPostEngagement extends Command
{
    protected $signature = 'posts:rollup-engagement';

    protected $description = 'Fold post_views/post_shares rows into the tbl_post views and shares counters';

    /** @var array<string, array{table: string, column: string, cursor: string}> */
    private const SOURCES = [
        'views' => [
            'table' => 'post_views',
            'column' => 'views',
            'cursor' => 'views_rollup_cursor',
        ],
        'shares' => [
            'table' => 'post_shares',
            'column' => 'shares',
            'cursor' => 'shares_rollup_cursor',
        ],
    ];

    public function handle(): int
    {
        $settings = GlobalSettings::first();
        if ($settings == null) {
            $this->error('No settings row; nothing to roll up against.');
            return self::FAILURE;
        }

        foreach (self::SOURCES as $label => $source) {
            $this->rollup($settings, $label, $source);
        }

        return self::SUCCESS;
    }

    private function rollup(GlobalSettings $settings, string $label, array $source): void
    {
        $cursorColumn = $source['cursor'];
        $low = (int) ($settings->{$cursorColumn} ?? 0);

        $high = (int) (DB::table($source['table'])->max('id') ?? 0);
        if ($high <= $low) {
            return;
        }

        $rows = DB::table($source['table'])
            ->selectRaw('post_id, COUNT(*) AS c')
            ->where('id', '>', $low)
            ->where('id', '<=', $high)
            ->groupBy('post_id')
            ->get();

        if ($rows->isEmpty()) {
            $settings->{$cursorColumn} = $high;
            $settings->save();
            return;
        }

        DB::transaction(function () use ($rows, $source, $settings, $cursorColumn, $high) {
            foreach ($rows as $row) {
                DB::table('tbl_post')
                    ->where('id', $row->post_id)
                    ->increment($source['column'], (int) $row->c);
            }

            // Persisted inside the same transaction as the increments so the
            // window can never be counted twice or skipped.
            $settings->{$cursorColumn} = $high;
            $settings->save();
        });

        $this->info("Rolled up {$rows->count()} post(s) worth of {$label} (cursor {$low} -> {$high}).");
    }
}
