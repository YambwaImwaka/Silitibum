<?php

namespace App\Console\Commands;

use App\Models\Livestream;
use App\Services\Live\HostPresence;
use App\Services\Live\LivestreamLiveness;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

// Ends live rooms whose host is gone (see LivestreamLiveness), and keeps the
// rooms of hosts that only show up in the presence channel listed.
class ReapStaleLivestreams extends Command
{
    protected $signature = 'livestreams:reap {--dry-run : Report what would be ended without ending it}';

    protected $description = 'End live rooms whose host has disappeared';

    public function handle(HostPresence $presence): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $quiet = Livestream::where('status', Livestream::STATUS_LIVE)
            ->where(function ($query) use ($now) {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $now->copy()->subSeconds(LivestreamLiveness::CHECK_AFTER));
            })
            ->orderBy('id')
            ->get();

        $ended = 0;
        foreach ($quiet as $stream) {
            $present = $presence->isHostPresent($stream);
            if ($present === true) {
                if (!$dryRun) {
                    LivestreamLiveness::seen($stream);
                }
                continue;
            }

            $lastSeen = Carbon::parse($stream->last_seen_at ?? $stream->created_at ?? $now->copy()->subDay());
            $unseenFor = $lastSeen->diffInSeconds($now);
            // Without an answer from Pusher, only rooms silent far longer than
            // any network drop are ended.
            $limit = $present === false
                ? LivestreamLiveness::REAP_AFTER
                : LivestreamLiveness::REAP_BLIND_AFTER;
            if ($unseenFor < $limit) {
                continue;
            }

            $this->line(sprintf('%s room %s (host %d, unseen %ds, presence %s)',
                $dryRun ? 'would end' : 'ending', $stream->room_id, $stream->host_id,
                $unseenFor, $present === null ? 'unknown' : 'absent'));
            if (!$dryRun && LivestreamLiveness::end($stream)) {
                $ended++;
            }
        }

        if ($ended > 0) {
            $this->info("Ended {$ended} stale livestream(s).");
        }
        return self::SUCCESS;
    }
}
