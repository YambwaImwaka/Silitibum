<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeVideoJob;
use App\Models\Constants;
use App\Models\Posts;
use Illuminate\Console\Command;

// Gives failed HLS transcodes another go, through the one-at-a-time queue.
// Most failures before October 2026 were concurrent encodes hitting the
// hosting account's limits, not bad videos, so a retry usually succeeds.
class RequeueFailedTranscodes extends Command
{
    protected $signature = 'videos:requeue-failed
                            {--days=14 : Only posts created in the last N days}
                            {--dry-run : List what would be queued}';

    protected $description = 'Queue failed video transcodes (video_status=3) of live posts for another attempt.';

    public function handle(): int
    {
        $posts = Posts::where('video_status', 3)
            ->whereIn('post_type', [Constants::postTypeReel, Constants::postTypeVideo])
            ->whereNotNull('video')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->get(['id', 'created_at']);

        if ($this->option('dry-run')) {
            $this->info("[dry run] {$posts->count()} failed transcode(s) would be queued: " . $posts->pluck('id')->implode(', '));
            return self::SUCCESS;
        }

        $queued = Posts::whereIn('id', $posts->pluck('id'))->where('video_status', 3)
            ->update(['video_status' => TranscodeVideoJob::STATUS_QUEUED, 'transcode_pid' => null]);
        $this->info("Queued {$queued} post(s) for transcoding; videos:poll-transcode-status works through them one at a time.");
        return self::SUCCESS;
    }
}
