<?php

namespace App\Console\Commands;

use App\Models\CommentLikes;
use App\Models\CommentReplies;
use App\Models\GlobalFunction;
use App\Models\PostComments;
use App\Models\Posts;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Permanently removes soft-deleted posts whose retention window has expired,
 * and prunes engagement rows that have already been rolled into the counters.
 *
 * This is the only scheduled path that calls deleteAllPostData() — the point
 * of no return. Everything up to here is recoverable from the admin trash
 * view, which is the whole reason deletion became soft.
 *
 * purge_after is a stored, indexed timestamp rather than a window recomputed
 * per row, so the sweep is one range scan no matter how much history exists.
 */
class PurgePostRetention extends Command
{
    protected $signature = 'posts:purge-retention
                            {--limit=200 : Maximum posts to purge in one run}
                            {--dry-run : Report what would be purged without deleting}';

    protected $description = 'Permanently delete soft-deleted posts past their retention window';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $expired = Posts::onlyTrashed()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', Carbon::now())
            ->orderBy('purge_after')
            ->limit($limit)
            ->get();

        foreach ($expired as $post) {
            $this->line("  purging post {$post->id} (deleted {$post->deleted_at}, source {$post->deletion_source})");
            if ($dryRun) {
                continue;
            }

            try {
                // Cascades the engagement rows and, unlike the old version,
                // actually removes the video/thumbnail/HLS media too.
                GlobalFunction::deleteAllPostData($post);
                $post->forceDelete();
            } catch (\Throwable $e) {
                $this->error("  failed to purge post {$post->id}: {$e->getMessage()}");
            }
        }

        $this->info(($dryRun ? 'Would purge ' : 'Purged ') . $expired->count() . ' post(s).');

        if (!$dryRun) {
            $this->purgeExpiredComments($limit);
            $this->pruneEngagementRows();
            $this->pruneOrphanedTranscodeScratch();
        }

        return self::SUCCESS;
    }

    /**
     * Comments and replies deleted on their own — i.e. whose post is still
     * live — expire on the same clock. Comments whose whole post was purged
     * are already gone via deleteAllPostData().
     */
    private function purgeExpiredComments(int $limit): void
    {
        $now = Carbon::now();

        $replies = CommentReplies::onlyTrashed()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', $now)
            ->limit($limit)
            ->get();
        foreach ($replies as $reply) {
            // comment_likes keys on comment_id only — replies carry no likes
            // of their own, so there is nothing to cascade here.
            $reply->forceDelete();
        }
        if ($replies->count() > 0) {
            $this->info("Purged {$replies->count()} expired reply(ies).");
        }

        $comments = PostComments::onlyTrashed()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', $now)
            ->limit($limit)
            ->get();
        foreach ($comments as $comment) {
            CommentReplies::withTrashed()->where('comment_id', $comment->id)->forceDelete();
            CommentLikes::where('comment_id', $comment->id)->delete();
            $comment->forceDelete();
        }
        if ($comments->count() > 0) {
            $this->info("Purged {$comments->count()} expired comment(s).");
        }
    }

    /**
     * post_views/post_shares rows exist only to deduplicate and to give the
     * trending window something to aggregate; once rolled into tbl_post and
     * outside that window they are dead weight on a shared-hosting database.
     */
    private function pruneEngagementRows(): void
    {
        $cutoff = Carbon::now()->subDays(90);

        foreach (['post_views', 'post_shares'] as $table) {
            $deleted = DB::table($table)->where('created_at', '<', $cutoff)->limit(5000)->delete();
            if ($deleted > 0) {
                $this->info("Pruned {$deleted} row(s) from {$table}.");
            }
        }
    }

    /**
     * PollTranscodeStatus skips soft-deleted posts, which is correct — there
     * is no point finishing an encode for content nobody can see — but it
     * means the scratch directory is never cleaned up by that path.
     */
    private function pruneOrphanedTranscodeScratch(): void
    {
        $root = storage_path('app/transcode');
        if (!is_dir($root)) {
            return;
        }

        foreach (File::directories($root) as $dir) {
            $postId = basename($dir);
            if (!ctype_digit($postId)) {
                continue;
            }
            // withTrashed(): a soft-deleted post may still be inside its
            // retention window, and its scratch dir goes with it at purge.
            $exists = Posts::withTrashed()->whereKey((int) $postId)->exists();
            if (!$exists) {
                File::deleteDirectory($dir);
                $this->info("Removed orphaned transcode scratch for post {$postId}.");
            }
        }
    }
}
