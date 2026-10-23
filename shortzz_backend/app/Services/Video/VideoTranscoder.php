<?php

namespace App\Services\Video;

use App\Models\GlobalFunction;
use App\Models\Posts;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

// Starts one detached ffmpeg HLS encode for a post (moved here unchanged from
// TranscodeVideoJob, which now only queues). Bound in the container so tests
// can stand in for the real process launch.
class VideoTranscoder
{
    /// Launches the encode and marks the post transcoding (status 1).
    /// Returns false (and marks the post failed) when it cannot start.
    public function launch(Posts $post): bool
    {
        $workDir = storage_path("app/transcode/{$post->id}");
        File::ensureDirectoryExists($workDir);
        // ffmpeg's hls muxer does not create the subdirectories implied by a
        // segment-filename pattern like "v%v/seg_%03d.ts" — they must already
        // exist, one per rendition (indices 0 and 1 for the 2-rung ladder
        // below).
        File::ensureDirectoryExists("{$workDir}/v0");
        File::ensureDirectoryExists("{$workDir}/v1");

        $ffmpegBinary = env('FFMPEG_BINARY_PATH', storage_path('app/bin/ffmpeg'));
        if (!is_executable($ffmpegBinary)) {
            Log::error("VideoTranscoder: ffmpeg binary not found/executable at {$ffmpegBinary}, post {$post->id}");
            $post->video_status = 3;
            $post->save();
            return false;
        }

        // Read over HTTP(S) rather than from local disk so this works
        // unchanged regardless of which storage backend is active
        // (PUBLIC/AWSS3/DOSPACE) — GlobalFunction::generateFileUrl already
        // knows how to build the right absolute URL for whichever is
        // configured.
        $sourceUrl = GlobalFunction::generateFileUrl($post->video);
        $logFile = "{$workDir}/ffmpeg.log";

        // Portrait-safe (scale by height, not a fixed landscape resolution —
        // reels are 9:16) 2-rendition ladder. veryfast bounds encode time on
        // what may be CPU-throttled shared hosting.
        // min(h,ih) caps each rung at the source height so a low-resolution
        // upload is never upscaled — upscaling costs real bytes for zero
        // added detail. Scaling by height with -2 width keeps portrait aspect
        // intact; the escaped comma keeps ffmpeg's filter parser from reading
        // min()'s argument separator as the end of the filter.
        $filterComplex = '[0:v]split=2[v1][v2];'
            . '[v1]scale=-2:min(480\,ih)[v1out];'
            . '[v2]scale=-2:min(720\,ih)[v2out]';
        $args = [
            // Before -i, -threads caps the *decoder*; the one further down
            // only caps the encoders. Left alone the H.264 decoder starts a
            // thread per core (12 here), and with the account's other
            // processes that crossed the LVE limit of 120: ffmpeg died with
            // "Resource temporarily unavailable" (-11) while configuring
            // filters, and every upload in that window failed to transcode.
            $ffmpegBinary, '-y', '-threads', '2', '-i', $sourceUrl,
            '-filter_complex', $filterComplex,
            '-map', '[v1out]', '-map', '[v2out]', '-map', '0:a', '-map', '0:a',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            // Distinct per-rendition caps are what give the two variants
            // different BANDWIDTH values in master.m3u8 — without them ffmpeg
            // advertises both at the same bitrate and the player has no basis
            // to switch, which silently defeats the whole point of ABR.
            '-maxrate:v:0', '1200k', '-bufsize:v:0', '1800k',
            '-maxrate:v:1', '2500k', '-bufsize:v:1', '3600k',
            // Two x264 encoders at default thread counts (~1.5x the 12 cores
            // nproc reports, each) exceed this CloudLinux/LVE account's limit:
            // the second encoder fails to start with "Could not open encoder
            // before EOF". Capping threads is what makes a multi-rendition
            // ladder possible here — and only ONE encode at a time (see
            // PollTranscodeStatus).
            '-threads', '2', '-filter_threads', '1',
            '-c:a', 'aac', '-b:a', '128k', '-ac', '2',
            '-f', 'hls', '-hls_time', '4', '-hls_playlist_type', 'vod',
            '-hls_flags', 'independent_segments',
            '-hls_segment_filename', 'v%v/seg_%03d.ts',
            '-master_pl_name', 'master.m3u8',
            '-var_stream_map', 'v:0,a:0 v:1,a:1',
            'v%v/prog.m3u8',
        ];

        // Built from an escaped argument array, never raw string
        // interpolation: files uploaded before GlobalFunction generated stored
        // names still carry the client's own filename.
        $cmd = implode(' ', array_map('escapeshellarg', $args));

        // Detach so this returns in milliseconds instead of blocking for the
        // whole encode. Every piece here is load-bearing:
        //   - `;` not `&&` before nohup: `cd X && nohup Y & echo $!` parses as
        //     `(cd X && nohup Y) & echo $!`, which backgrounds a *subshell*
        //     that holds this process's stdout pipe until ffmpeg exits, and
        //     `$!` would be the subshell's pid, not ffmpeg's.
        //   - stdout/stderr to a log file and stdin from /dev/null: nothing
        //     left holding the parent's file descriptors.
        $detached = 'cd ' . escapeshellarg($workDir) . '; nohup ' . $cmd
            . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';

        exec($detached, $output, $exitCode);
        $pid = (int) trim($output[0] ?? '0');

        if ($pid <= 0) {
            Log::error("VideoTranscoder: failed to launch ffmpeg for post {$post->id}, exec exit={$exitCode}");
            $post->video_status = 3;
            $post->save();
            return false;
        }

        // Direct assignment rather than ->update([...]): Posts declares no
        // $fillable, so every mass assignment throws MassAssignmentException.
        $post->video_status = 1;
        $post->transcode_pid = $pid;
        $post->transcode_started_at = now();
        $post->save();
        return true;
    }

    public function isAlive(?int $pid): bool
    {
        return $pid !== null && $pid > 0 && file_exists("/proc/{$pid}");
    }

    public function kill(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 9);
        } else {
            exec('kill -9 ' . escapeshellarg((string) $pid));
        }
    }
}
