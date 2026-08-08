<?php

namespace App\Http\Controllers;

use App\Jobs\TranscodeVideoJob;
use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Models\Livestream;
use App\Models\Posts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Stores the broadcast itself, so a live is no longer gone the moment the room
 * closes.
 *
 * Zego carries the media and discards it at teardown, and Zego's cloud
 * recording is a paid add-on — so the recording is made by the host's own
 * device (ZegoExpressEngine.startRecordingCapturedData) and uploaded here when
 * the stream ends. Once assembled it becomes an ordinary video post owned by
 * the host, which means replay, feed placement, moderation, reporting, soft
 * delete and HLS transcoding all come for free from the post pipeline instead
 * of needing a second one.
 *
 * Uploaded in chunks rather than as one multipart POST for two reasons that
 * both bite in production: PHP's post_max_size is 128M here (a 40-minute live
 * comfortably exceeds that), and the upload happens from a phone on mobile
 * data, where a single long request is the thing most likely to be dropped.
 * Each chunk is its own small request that can be retried on its own.
 */
class LivestreamRecordingController extends Controller
{
    // No chunk should ever approach post_max_size (128M in production); the app
    // sends 6MB. The ceiling here is a guard against a malformed or hostile
    // client, not the expected size.
    private const MAX_CHUNK_BYTES = 16 * 1024 * 1024;

    // ~2GB at the app's chunk size. A cap has to exist because the parts are
    // written to this account's disk before anything validates the whole file.
    private const MAX_CHUNKS = 400;

    private const STATUS_NONE = 0;
    private const STATUS_UPLOADING = 1;
    private const STATUS_STORED = 2;
    private const STATUS_FAILED = 3;

    private function scratchDir($streamId): string
    {
        return storage_path('app/live_recordings/' . (int) $streamId);
    }

    /**
     * Resolves the stream and asserts the caller is its host.
     *
     * Not scoped to STATUS_LIVE: the upload runs *after* the host ends the
     * room, so requiring a live stream here would reject every real request.
     */
    private function hostedStream(Request $request, $user)
    {
        $stream = Livestream::where('room_id', $request->room_id)->first();
        if ($stream == null) {
            return [null, 'livestream not found'];
        }
        if ($stream->host_id != $user->id) {
            return [null, 'only the host can store this livestream'];
        }
        return [$stream, null];
    }

    /**
     * Receives one slice of the recording.
     *
     * Chunks are written under their index rather than appended to a single
     * file, so a retried or out-of-order chunk overwrites its own slot instead
     * of corrupting the stream — the app retries individual chunks on a flaky
     * connection and that has to be safe.
     */
    public function uploadRecordingChunk(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'chunk_index' => 'required|integer|min:0|max:' . (self::MAX_CHUNKS - 1),
            'total_chunks' => 'required|integer|min:1|max:' . self::MAX_CHUNKS,
            'chunk' => 'required|file|max:' . (self::MAX_CHUNK_BYTES / 1024),
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        $settings = GlobalSettings::first();
        if ((int) ($settings->livestream_recording_enabled ?? 1) !== 1) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream recording is disabled');
        }

        [$stream, $error] = $this->hostedStream($request, $user);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }
        // Already published: the app is retrying a finalize it never saw the
        // response to. Accepting more chunks would only write bytes nobody
        // will ever assemble.
        if ($stream->recording_post_id != null) {
            return GlobalFunction::sendSimpleResponse(true, 'recording already stored');
        }

        $dir = $this->scratchDir($stream->id);
        File::ensureDirectoryExists($dir);

        // sprintf-padded so the parts sort in index order — finalize()
        // concatenates by glob, and 'part_10' sorting before 'part_2' would
        // silently produce a scrambled video rather than an error.
        $request->file('chunk')->move($dir, sprintf('part_%04d', (int) $request->chunk_index));

        if ((int) $stream->recording_status !== self::STATUS_UPLOADING) {
            $stream->recording_status = self::STATUS_UPLOADING;
            $stream->save();
        }

        return GlobalFunction::sendDataResponse(true, 'chunk received', [
            'received' => count(File::glob($dir . '/part_*')),
            'total_chunks' => (int) $request->total_chunks,
        ]);
    }

    /**
     * Assembles the chunks and publishes the result as the host's video post.
     *
     * Idempotent on recording_post_id: a client that retries after a dropped
     * response gets the post that already exists rather than publishing the
     * same live a second time.
     */
    public function finalizeLivestreamRecording(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'total_chunks' => 'required|integer|min:1|max:' . self::MAX_CHUNKS,
            'duration_ms' => 'nullable|integer|min:0',
            'thumbnail' => 'nullable|string',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        [$stream, $error] = $this->hostedStream($request, $user);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }
        if ($stream->recording_post_id != null) {
            $existing = Posts::find($stream->recording_post_id);
            return GlobalFunction::sendDataResponse(true, 'recording already stored',
                $existing != null ? GlobalFunction::preparePostFullData($existing->id) : null);
        }

        $dir = $this->scratchDir($stream->id);
        $parts = File::glob($dir . '/part_*');
        sort($parts);
        $expected = (int) $request->total_chunks;
        if (count($parts) !== $expected) {
            // Deliberately does not clean up: the missing chunks can still be
            // re-sent and finalize retried, which is the whole point of
            // uploading in slices.
            return GlobalFunction::sendSimpleResponse(false,
                'recording incomplete: got ' . count($parts) . ' of ' . $expected . ' chunks');
        }

        // The thumbnail the app captured seconds into the broadcast is already
        // stored as the stream's snapshot, so a recording normally needs no
        // second upload for it.
        $thumbnail = $request->filled('thumbnail') ? $request->thumbnail : $stream->snapshot;
        if (empty($thumbnail)) {
            $this->markFailed($stream);
            File::deleteDirectory($dir);
            return GlobalFunction::sendSimpleResponse(false, 'recording has no thumbnail to publish with');
        }

        $disk = GlobalFunction::resolveStorageDisk();
        if ($disk === null) {
            Log::error('finalizeLivestreamRecording: FILES_STORAGE_LOCATION is not a supported value');
            return GlobalFunction::sendSimpleResponse(false, 'storage is not configured');
        }

        $tmpFile = $dir . '/assembled.mp4';
        try {
            $this->concatenate($parts, $tmpFile);

            // Streamed rather than file_get_contents(): a long live is
            // comfortably larger than the 512M memory_limit this account runs
            // under, and reading it whole would fatal instead of storing.
            $storedPath = 'uploads/' . time() . '_live_' . $stream->id . '.mp4';
            $handle = fopen($tmpFile, 'rb');
            Storage::disk($disk)->writeStream($storedPath, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }
        } catch (\Throwable $e) {
            Log::error("finalizeLivestreamRecording: assembly failed for stream {$stream->id}: {$e->getMessage()}");
            $this->markFailed($stream);
            File::deleteDirectory($dir);
            return GlobalFunction::sendSimpleResponse(false, 'could not store the recording');
        }

        File::deleteDirectory($dir);

        // Built directly rather than through GlobalFunction::generatePost():
        // that helper reads hashtags, mentions, location and sound off the
        // request and fans out notifications for them, none of which a live
        // recording has. It also runs checkIfUserCanPost's daily upload cap,
        // which must not apply here — the broadcast already happened, and
        // silently discarding its only copy because the host posted a lot that
        // day would lose content rather than throttle it.
        $post = new Posts();
        $post->user_id = $user->id;
        $post->post_type = Constants::postTypeVideo;
        $post->can_comment = (int) ($request->can_comment ?? 1);
        $post->description = GlobalFunction::cleanString(
            $request->description ?? $stream->description ?? ''
        );
        $post->video = $storedPath;
        $post->thumbnail = $thumbnail;
        $post->save();

        // Same upgrade path as any other video post: it serves the raw MP4
        // immediately and switches to adaptive-bitrate HLS once this finishes.
        TranscodeVideoJob::dispatch($post->id);

        // The recording is a normal post, so it goes through the same "this is
        // now visible to other people" hook — followers see an ended live in
        // their feed exactly as they would any upload.
        GlobalFunction::publishPost($post, $user);

        $stream->recording = $storedPath;
        $stream->recording_post_id = $post->id;
        $stream->recording_duration_ms = $request->duration_ms !== null
            ? (int) $request->duration_ms : null;
        $stream->recording_status = self::STATUS_STORED;
        $stream->save();

        return GlobalFunction::sendDataResponse(true, 'livestream saved to your profile',
            GlobalFunction::preparePostFullData($post->id));
    }

    /**
     * Lets a host abandon an upload it cannot finish, so the parts already sent
     * do not sit on disk until the retention sweep notices them.
     */
    public function discardLivestreamRecording(Request $request)
    {
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        [$stream, $error] = $this->hostedStream($request, $user);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }
        if ($stream->recording_post_id != null) {
            return GlobalFunction::sendSimpleResponse(false,
                'this livestream is already published — delete the post instead');
        }

        File::deleteDirectory($this->scratchDir($stream->id));
        $stream->recording_status = self::STATUS_NONE;
        $stream->save();

        return GlobalFunction::sendSimpleResponse(true, 'recording discarded');
    }

    private function markFailed($stream): void
    {
        $stream->recording_status = self::STATUS_FAILED;
        $stream->save();
    }

    /**
     * Streams the parts into one file. Chunk-at-a-time rather than
     * file_get_contents() per part for the same memory reason as the upload
     * above — the assembled result routinely exceeds the memory limit.
     */
    private function concatenate(array $parts, string $destination): void
    {
        $out = fopen($destination, 'wb');
        if ($out === false) {
            throw new \RuntimeException('could not open ' . $destination);
        }

        try {
            foreach ($parts as $part) {
                $in = fopen($part, 'rb');
                if ($in === false) {
                    throw new \RuntimeException('could not read chunk ' . $part);
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }
    }
}
