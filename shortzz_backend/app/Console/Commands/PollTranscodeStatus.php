<?php

namespace App\Console\Commands;

use App\Models\GlobalFunction;
use App\Models\Posts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// The other half of TranscodeVideoJob: that job only *launches* a detached
// ffmpeg process and records its PID (see that class for why it can't wait
// for the encode to finish). This command, run every minute, is what
// actually notices a transcode finished, failed, or hung, and does the
// upload-to-storage + DB update that turns a raw MP4 post into an
// adaptive-bitrate HLS one.
class PollTranscodeStatus extends Command
{
    protected $signature = 'videos:poll-transcode-status';

    protected $description = 'Check in-progress video transcodes (video_status=1): upload finished HLS output, fail hung/dead ones, leave the rest for the next run.';

    // A short reel/feed-video encode at -preset veryfast should finish in
    // well under this; treated as a hard failure past this point so a
    // wedged/OOM-killed-but-zombie ffmpeg (or one that somehow never wrote
    // output) can't leave a post stuck "transcoding" forever.
    private const TIMEOUT_MINUTES = 20;

    public function handle(): int
    {
        $inProgress = Posts::where('video_status', 1)->get();
        if ($inProgress->isEmpty()) {
            $this->info('Nothing in progress.');
            return self::SUCCESS;
        }

        $uploaded = 0;
        $failed = 0;
        $stillRunning = 0;

        foreach ($inProgress as $post) {
            $workDir = storage_path("app/transcode/{$post->id}");
            $pid = $post->transcode_pid;
            $alive = $pid && file_exists("/proc/{$pid}");

            if ($alive) {
                $startedAt = $post->transcode_started_at;
                if ($startedAt !== null && now()->diffInMinutes($startedAt) > self::TIMEOUT_MINUTES) {
                    $this->warn("post {$post->id}: transcode exceeded " . self::TIMEOUT_MINUTES . "min, killing pid {$pid}");
                    $this->killProcess($pid);
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

        $this->info("Done. uploaded={$uploaded} failed={$failed} still-running={$stillRunning}");
        return self::SUCCESS;
    }

    private function killProcess(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 9);
        } else {
            exec('kill -9 ' . escapeshellarg((string) $pid));
        }
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
}
