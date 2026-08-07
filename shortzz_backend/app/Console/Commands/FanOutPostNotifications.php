<?php

namespace App\Console\Commands;

use App\Models\Constants;
use App\Models\Followers;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\Posts;
use App\Models\UserBlocks;
use App\Models\Users;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Notifies an author's followers that they posted.
 *
 * Runs out of band because QUEUE_CONNECTION is sync here with no worker: doing
 * this inline would make an upload take as long as the author has followers.
 * GlobalFunction::publishPost() enqueues one row; this walks the follower list
 * in chunks, keyset-paginated on from_user_id, and resumes from cursor_user_id
 * on the next tick rather than restarting.
 *
 * Bounded by wall clock rather than row count so a run always finishes well
 * inside CloudLinux's per-process limits on shared hosting.
 */
class FanOutPostNotifications extends Command
{
    protected $signature = 'posts:fanout';

    protected $description = 'Notify followers about newly published posts';

    /** Seconds of wall clock one run may consume before deferring the rest. */
    private const TIME_BUDGET_SECONDS = 40;

    /** Followers read per query. */
    private const CHUNK = 500;

    /** Max pushes attempted per run (each is an HTTPS round trip). */
    private const PUSH_BUDGET = 300;

    private int $pushesSent = 0;

    public function handle(): int
    {
        $startedAt = microtime(true);

        $jobs = DB::table('post_fanout_queue')
            ->whereIn('status', [0, 1])
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($jobs as $job) {
            if ((microtime(true) - $startedAt) > self::TIME_BUDGET_SECONDS) {
                $this->info('Time budget reached; remaining jobs resume next tick.');
                break;
            }

            $this->processJob($job, $startedAt);
        }

        return self::SUCCESS;
    }

    private function processJob($job, float $startedAt): void
    {
        // withTrashed() is wrong here on purpose: a post deleted between
        // upload and fan-out should not be announced to anyone.
        $post = Posts::find($job->post_id);
        if ($post == null) {
            $this->markDone($job->id, $job->total_sent);
            return;
        }

        $author = Users::find($post->user_id);
        if ($author == null || $author->is_freez == 1) {
            $this->markDone($job->id, $job->total_sent);
            return;
        }

        $settings = GlobalSettings::first();
        $maxFollowers = (int) ($settings->fanout_max_followers ?? 20000);

        $followerCount = Followers::where('to_user_id', $author->id)->count();
        if ($followerCount > $maxFollowers) {
            // One celebrity post would otherwise write hundreds of thousands
            // of notification rows. Skip the per-follower path entirely.
            $this->warn("Post {$post->id}: {$followerCount} followers exceeds cap {$maxFollowers}; skipping DB fan-out.");
            DB::table('post_fanout_queue')->where('id', $job->id)->update([
                'status' => 3,
                'updated_at' => Carbon::now(),
            ]);
            return;
        }

        DB::table('post_fanout_queue')->where('id', $job->id)->update([
            'status' => 1,
            'attempts' => $job->attempts + 1,
            'updated_at' => Carbon::now(),
        ]);

        $cursor = (int) $job->cursor_user_id;
        $totalSent = (int) $job->total_sent;
        $title = $author->fullname ?: $author->username;
        $body = 'posted new content';

        while (true) {
            if ((microtime(true) - $startedAt) > self::TIME_BUDGET_SECONDS) {
                $this->persistCursor($job->id, $cursor, $totalSent);
                return;
            }

            $followerIds = Followers::where('to_user_id', $author->id)
                ->where('from_user_id', '>', $cursor)
                ->orderBy('from_user_id')
                ->limit(self::CHUNK)
                ->pluck('from_user_id')
                ->all();

            if (count($followerIds) === 0) {
                $this->markDone($job->id, $totalSent);
                $this->info("Post {$post->id}: fan-out complete ({$totalSent} notified).");
                return;
            }

            $cursor = (int) end($followerIds);

            $recipients = Users::whereIn('id', $followerIds)
                ->where('is_freez', 0)
                ->where('notify_new_post', 1)
                ->get(['id', 'device', 'device_token', 'notify_new_post']);

            // Someone who blocked the author (or is blocked by them) should
            // not be told they posted.
            $blockedIds = UserBlocks::where(function ($q) use ($author) {
                $q->where('from_user_id', $author->id)->orWhere('to_user_id', $author->id);
            })->pluck('from_user_id')
              ->merge(
                  UserBlocks::where(function ($q) use ($author) {
                      $q->where('from_user_id', $author->id)->orWhere('to_user_id', $author->id);
                  })->pluck('to_user_id')
              )->unique()->flip();

            $rows = [];
            $now = Carbon::now();
            foreach ($recipients as $recipient) {
                if ($recipient->id == $author->id || $blockedIds->has($recipient->id)) {
                    continue;
                }

                $rows[] = [
                    'type' => Constants::notify_new_post_from_following,
                    'from_user_id' => $author->id,
                    'to_user_id' => $recipient->id,
                    'data_id' => $post->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($rows) > 0) {
                // One bulk insert per chunk, not insertUserNotification()'s
                // one INSERT per follower.
                DB::table('notification_users')->insert($rows);
                $totalSent += count($rows);
            }

            // FCM HTTP v1 is strictly single-target — there is no multicast,
            // and the legacy batch endpoint is retired. Kept behind its own
            // budget so a big fan-out cannot monopolise the scheduler tick;
            // the DB rows above are what the in-app Activity list reads, so a
            // deferred push never means a lost notification.
            foreach ($recipients as $recipient) {
                if ($this->pushesSent >= self::PUSH_BUDGET) {
                    break;
                }
                if ($recipient->id == $author->id || $blockedIds->has($recipient->id)) {
                    continue;
                }
                if (empty($recipient->device_token)) {
                    continue;
                }

                GlobalFunction::pushToUser(
                    $recipient->id,
                    $title,
                    $body,
                    [
                        'type' => (string) Constants::notify_new_post_from_following,
                        'id' => (string) $post->id,
                    ],
                    'notify_new_post'
                );
                $this->pushesSent++;
            }

            $this->persistCursor($job->id, $cursor, $totalSent);
        }
    }

    private function persistCursor($jobId, int $cursor, int $totalSent): void
    {
        DB::table('post_fanout_queue')->where('id', $jobId)->update([
            'cursor_user_id' => $cursor,
            'total_sent' => $totalSent,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function markDone($jobId, int $totalSent): void
    {
        DB::table('post_fanout_queue')->where('id', $jobId)->update([
            'status' => 2,
            'total_sent' => $totalSent,
            'updated_at' => Carbon::now(),
        ]);
    }
}
