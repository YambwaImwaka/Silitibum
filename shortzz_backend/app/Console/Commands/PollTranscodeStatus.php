<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeVideoJob;
use App\Models\GlobalFunction;
use App\Models\Posts;
use App\Services\Video\VideoTranscoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Drives video transcoding, one encode at a time. Every minute (never
// overlapping itself — see Console\Kernel) it:
//   1. checks the encode in progress (video_status=1): uploads finished HLS
//      output, fails dead or hung runs, and cleans up encodes of posts that
//      were deleted meanwhile (those used to sit "transcoding" forever, since
//      a plain query never sees soft-deleted posts);
//   2. if nothing is encoding, starts the oldest queued post (status 4).
// Only one ffmpeg at a time because this hosting account's process/thread
// limit makes concurrent encodes fail outright (see TranscodeVideoJob).
class PollTranscodeStatus extends Command
{
    protected $signature = 'videos:poll-transcode-status';

    protected $description = 'Finish or fail the running video transcode, then start the next queued one (one at a time).';

    // A short reel/feed-video encode at -preset veryfast should finish in
    // well under this; treated as a hard failure past this point so a
    // wedged/OOM-killed-but-zombie ffmpeg can't leave a post stuck
    // "transcoding" (and block the queue) forever.
    private const TIMEOUT_MINUTES = 20;

    public function handle(VideoTranscoder $transcoder): int
    {
        $uploaded = 0;
        $failed = 0;
        $stillRunning = 0;

        foreach (Posts::withTrashed()->where('video_status', 1)->get() as $post) {
            $workDir = storage_path("app/transcode/{$post->id}");
            $pid = $post->transcode_pid;
            $alive = $transcoder->isAlive($pid);

            if ($post->trashed()) {
                if ($alive) {
                    $transcoder->kill((int) $pid);
                }
                $this->abandon($post, $workDir);
                continue;
            }

            if ($alive) {
                $startedAt = $post->transcode_started_at;
                if ($startedAt !== null && now()->diffInMinutes($startedAt) > self::TIMEOUT_MINUTES) {
                    $this->warn("post {$post->id}: transcode exceeded " . self::TIMEOUT_MINUTES . "min, killing pid {$pid}");
                    $transcoder->kill((int) $pid);
                    $this->failPost($post, $workDir, 'timed out');
                    $failed++;
                } else {
                    $stillRunning++;
                }
                continue;
            }

            // Process has exited — check whether it left valid output behind.
            if ($this->hasValidOutput($workDir)) {
                if ($this->uploadOutput($post, $workDir)) {
                    $uploaded++;
                    $this->info("post {$post->id}: HLS ready");
                } else {
                    $this->failPost($post, $workDir, 'upload to storage failed');
                    $failed++;
                }
            } else {
                $this->failPost($post, $workDir, 'process exited without valid output');
                $failed++;
            }
        }

        $started = 0;
        if ($stillRunning === 0) {
            $next = Posts::where('video_status', TranscodeVideoJob::STATUS_QUEUED)->orderBy('id')->first();
            if ($next !== null) {
                if (empty($next->video)) {
                    $next->video_status = 3;
                    $next->save();
                } elseif ($transcoder->launch($next)) {
                    $started = 1;
                    $this->info("post {$next->id}: transcode started");
                }
            }
        }

        $queued = Posts::where('video_status', TranscodeVideoJob::STATUS_QUEUED)->count();
        $this->info("Done. uploaded={$uploaded} failed={$failed} still-running={$stillRunning} started={$started} queued={$queued}");
        return self::SUCCESS;
    }

    private function hasValidOutput(string $workDir): bool
    {
        $master = "{$workDir}/master.m3u8";
        if (!is_file($master) || filesize($master) === 0) {
            return false;
        }
        foreach (['v0', 'v1'] as $rendition) {
            $playlist = "{$workDir}/{$rendition}/prog.m3u8";
            if (!is_file($playlist) || filesize($playlist) === 0) {
                return false;
            }
            $segments = glob("{$workDir}/{$rendition}/*.ts");
            if (empty($segments)) {
                return false;
            }
        }
        return true;
    }

    private function uploadOutput(Posts $post, string $workDir): bool
    {
        $disk = GlobalFunction::resolveStorageDisk();
        if ($disk === null) {
            Log::error("PollTranscodeStatus: no storage disk resolved (FILES_STORAGE_LOCATION unset?) for post {$post->id}");
            return false;
        }

        try {
            $prefix = "hls/{$post->id}";
            foreach (File::allFiles($workDir) as $file) {
                if ($file->getFilename() === 'ffmpeg.log') continue;
                $relative = str_replace('\\', '/', $file->getRelativePathname());
                Storage::disk($disk)->put("{$prefix}/{$relative}", file_get_contents($file->getPathname()), 'public');
            }
        } catch (\Throwable $e) {
            Log::error("PollTranscodeStatus: upload failed for post {$post->id}: {$e->getMessage()}");
            return false;
        }

        // Direct assignment rather than ->update([...]): Posts declares no
        // $fillable, so every mass assignment throws MassAssignmentException.
        $post->video_status = 2;
        $post->hls_path = "hls/{$post->id}/master.m3u8";
        $post->transcode_pid = null;
        $post->save();
        File::deleteDirectory($workDir);
        return true;
    }

    private function failPost(Posts $post, string $workDir, string $reason): void
    {
        $logTail = '';
        $logFile = "{$workDir}/ffmpeg.log";
        if (is_file($logFile)) {
            $lines = @file($logFile) ?: [];
            $logTail = implode('', array_slice($lines, -20));
        }
        Log::error("PollTranscodeStatus: post {$post->id} failed ({$reason}). ffmpeg.log tail:\n{$logTail}");

        $post->video_status = 3;
        $post->transcode_pid = null;
        $post->save();
        if (is_dir($workDir)) {
            File::deleteDirectory($workDir);
        }
    }

    // The post was deleted while (or before) it encoded: nothing to publish.
    private function abandon(Posts $post, string $workDir): void
    {
        $post->video_status = 3;
        $post->transcode_pid = null;
        $post->save();
        if (is_dir($workDir)) {
            File::deleteDirectory($workDir);
        }
        $this->line("post {$post->id}: deleted — encode abandoned and cleaned up");
    }
}
