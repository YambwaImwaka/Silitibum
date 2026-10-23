<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Catches top-ups the provider charged successfully but whose webhook
        // never reached us — otherwise the customer's money is gone and their
        // coins never arrive, silently and permanently (this is what stranded
        // top-up 1). withoutOverlapping() because verify() is a network call
        // per row and runs can outlast the interval on a slow provider.
        $schedule->command('topups:reconcile')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // The payout-side twin: automated payouts whose provider webhook never
        // arrived. Only a provider-confirmed result changes anything.
        $schedule->command('payouts:reconcile')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Proves every wallet balance against the ledger; a mismatch is
        // logged as an error (something changed money outside WalletLedger).
        $schedule->command('wallet:reconcile')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->runInBackground();

        // TranscodeVideoJob only launches ffmpeg and records its PID (a
        // queued job can't safely wait out an encode — see that class); this
        // is what actually notices completion/failure/hangs and uploads the
        // finished HLS output. schedule:run is already active in production
        // (confirmed via crontab -l) so this needs no new cron entry.
        $schedule->command('videos:poll-transcode-status')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Ends live rooms whose host's app died without ending them, and
        // re-confirms hosts that are only visible in the presence channel, so
        // the Live tab lists real broadcasts only (LivestreamLiveness).
        $schedule->command('livestreams:reap')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Views/shares are written as deduplicated rows per viewer per day and
        // folded into the tbl_post counters here, so a viral post takes one
        // row lock per run instead of one per viewer. Five minutes of
        // staleness is invisible: FeedRankingService only reads these as a
        // normalised ratio.
        $schedule->command('posts:rollup-engagement')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Deleted posts are retained (see the retention migration) so a
        // takedown can still be evidenced if challenged. This is what finally
        // discards them once that window expires, and the only scheduled path
        // that removes anything permanently.
        $schedule->command('posts:purge-retention')
            ->daily()
            ->withoutOverlapping()
            ->runInBackground();

        // "Someone you follow posted" notifications. Enqueued as a single row
        // by the upload so posting stays O(1); this walks the follower list in
        // chunks across ticks, bounded by a wall-clock budget per run.
        $schedule->command('posts:fanout')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Trending ranking. Four windowed aggregates over the engagement
        // tables — far too expensive to run per Explore open, so it is
        // precomputed and the read path becomes one indexed join.
        $schedule->command('posts:compute-trending')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
