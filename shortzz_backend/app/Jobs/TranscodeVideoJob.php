<?php

namespace App\Jobs;

use App\Models\GlobalFunction;
use App\Models\Posts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

// Launches ffmpeg as a detached background process for one post's video and
// returns immediately — it deliberately does NOT wait for the encode to
// finish. Two production constraints make waiting unsafe:
//   - config/queue.php's database connection has retry_after=90: a job whose
//     handle() runs longer than that gets picked up a SECOND time, producing
//     a duplicate ffmpeg run against the same output paths.
//   - the worker cron is `queue:work --stop-when-empty --max-time=50`, which
//     kills the whole worker process at 50s regardless.
// A multi-rendition HLS encode isn't guaranteed to finish inside either
// window, so this job's job is just to *start* the encode and record its
// PID; PollTranscodeStatus (run every minute via the scheduler, which is
// already active in production — confirmed via `crontab -l`) checks later
// whether it finished, failed, or hung.
class TranscodeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // handle() only launches a detached process, so it should finish in
    // milliseconds. A generous-but-finite ceiling well under the queue's
    // retry_after=90 means that if detaching ever regresses into blocking on
    // the encode again, this fails fast and visibly instead of being retried
    // as an abandoned job and spawning a duplicate ffmpeg run.
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
            Log::error("TranscodeVideoJob: ffmpeg binary not found/executable at {$ffmpegBinary}, post {$post->id}");
            $post->video_status = 3;
            $post->save();
            return;
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
        // what may be CPU-throttled shared hosting; start with 2 rungs and
        // only add a 3rd once real wall-clock encode times are measured
        // (see the plan doc's Phase 3.5/3.8).
        // min(h,ih) caps each rung at the source height so a low-resolution
        // upload is never upscaled — upscaling costs real bytes for zero
        // added detail (a 352x624 source stretched to 720p produced an 8.4MB
        // rendition vs 7.1MB at native size). Scaling by height with -2 width
        // keeps portrait aspect intact; the escaped comma keeps ffmpeg's
        // filter parser from reading min()'s argument separator as the end of
        // the filter.
        $filterComplex = '[0:v]split=2[v1][v2];'
            . '[v1]scale=-2:min(480\,ih)[v1out];'
            . '[v2]scale=-2:min(720\,ih)[v2out]';
        $args = [
            $ffmpegBinary, '-y', '-i', $sourceUrl,
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
            // before EOF" and the split filter dies with "Error sending frames
            // to consumers: Generic error in an external library". Capping
            // threads is what makes a multi-rendition ladder possible here.
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
        // interpolation. GlobalFunction::saveFileAndGivePath now generates
        // stored names itself, but files uploaded before that still carry the
        // client's own filename, which is a shell-injection vector if this
        // were ever a concatenated string command instead.
        $escaped = array_map('escapeshellarg', $args);
        $cmd = implode(' ', $escaped);

        // Detach so this returns in milliseconds instead of blocking for the
        // whole encode. Every piece here is load-bearing:
        //   - `;` not `&&` before nohup: `cd X && nohup Y & echo $!` parses as
        //     `(cd X && nohup Y) & echo $!`, which backgrounds a *subshell*.
        //     That subshell holds this process's stdout pipe open until ffmpeg
        //     exits, so exec() blocks reading it for the full encode (~79s
        //     observed) and the job dies on Laravel's 60s timeout — and `$!`
        //     would be the subshell's pid, not ffmpeg's, so the poller would
        //     be watching the wrong process too.
        //   - stdout/stderr to a log file and stdin from /dev/null: nothing
        //     left holding the parent's file descriptors, which is what lets
        //     exec() see EOF immediately and what keeps ffmpeg alive after
        //     the worker exits at --max-time=50.
        $detached = 'cd ' . escapeshellarg($workDir) . '; nohup ' . $cmd
            . ' > ' . escapeshellarg($logFile) . ' 2>&1 < /dev/null & echo $!';

        exec($detached, $output, $exitCode);
        $pid = (int) trim($output[0] ?? '0');

        if ($pid <= 0) {
            Log::error("TranscodeVideoJob: failed to launch ffmpeg for post {$post->id}, exec exit={$exitCode}");
            $post->video_status = 3;
            $post->save();
            return;
        }

        // Direct assignment rather than ->update([...]): Posts declares no
        // $fillable, so every mass assignment throws MassAssignmentException.
        $post->video_status = 1;
        $post->transcode_pid = $pid;
        $post->transcode_started_at = now();
        $post->save();
    }
}
