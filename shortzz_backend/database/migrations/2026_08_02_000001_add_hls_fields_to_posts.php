<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adaptive-bitrate HLS support for reels/feed videos (tbl_post, shared by
// post_type=1 reel and post_type=3 video). Uploads still publish immediately
// with the raw `video` MP4 (served fast off S3/CloudFront) — a background
// job (TranscodeVideoJob, app/Jobs) encodes an HLS rendition ladder and, once
// done, sets hls_path so the client switches to adaptive playback on its next
// fetch. transcode_pid/transcode_started_at let the completion poller
// (PollTranscodeStatus) tell "still running" apart from "died/hung" without
// blocking the queue worker on a CPU-bound ffmpeg process (see the plan doc
// for why this can't just run inside a normal queued job's handle()).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_post', function (Blueprint $table) {
            // 0=raw (video only, not yet transcoded) 1=transcoding 2=ready (hls_path set) 3=failed
            $table->unsignedTinyInteger('video_status')->default(0)->after('video');
            // Relative key to the HLS master playlist, same convention as
            // `video`/`thumbnail` (a storage-disk-relative path, not a full
            // URL — Flutter's addBaseURL() prepends itemBaseUrl). Null until
            // video_status reaches 2.
            $table->string('hls_path', 555)->nullable()->after('video_status');
            $table->unsignedInteger('transcode_pid')->nullable()->after('hls_path');
            $table->timestamp('transcode_started_at')->nullable()->after('transcode_pid');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_post', function (Blueprint $table) {
            $table->dropColumn(['video_status', 'hls_path', 'transcode_pid', 'transcode_started_at']);
        });
    }
};
