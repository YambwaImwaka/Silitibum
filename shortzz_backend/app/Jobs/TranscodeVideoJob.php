<?php

namespace App\Jobs;

use App\Models\Posts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

// Queues a post's video for HLS transcoding. It no longer starts ffmpeg
// itself: on this CloudLinux/LVE account, two encodes running at once exceed
// the process/thread limit and BOTH fail ("Could not open encoder before
// EOF") — every burst of uploads (Oct 2026: posts 418-429, 440-445) lost its
// HLS that way while single uploads succeeded. PollTranscodeStatus (every
// minute, never overlapping itself) is now the only launcher and runs one
// encode at a time, oldest queued first. The post keeps serving its raw MP4
// until its HLS is ready (the app only switches on video_status 2).
class TranscodeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Waiting for its turn. Distinct from 0 (never queued — every post from
    // before the HLS pipeline), so the poller never sweeps up that backlog.
    public const STATUS_QUEUED = 4;

    public $timeout = 30;

    public $tries = 1;

    public function __construct(public int $postId)
    {
    }

    public function handle(): void
    {
        $post = Posts::find($this->postId);
        if ($post === null || empty($post->video)) {
            return;
        }
        // Direct assignment: Posts declares no $fillable.
        $post->video_status = self::STATUS_QUEUED;
        $post->transcode_pid = null;
        $post->save();
    }
}
