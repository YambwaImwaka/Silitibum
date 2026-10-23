<?php

namespace Tests\Feature;

use App\Jobs\TranscodeVideoJob;
use App\Models\Posts;
use App\Services\Video\VideoTranscoder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

// Concurrent ffmpeg encodes fail on this hosting account, so transcoding must
// run strictly one at a time, oldest first.
class TranscodeQueueTest extends ApiTestCase
{
    private object $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new class extends VideoTranscoder {
            public array $launched = [];
            public array $alive = [];
            public function launch(Posts $post): bool
            {
                $this->launched[] = $post->id;
                $post->video_status = 1;
                $post->transcode_pid = 900000 + $post->id;
                $post->transcode_started_at = now();
                $post->save();
                $this->alive[$post->transcode_pid] = true;
                return true;
            }
            public function isAlive(?int $pid): bool
            {
                return $pid !== null && !empty($this->alive[$pid]);
            }
            public function kill(int $pid): void
            {
                unset($this->alive[$pid]);
            }
        };
        $this->app->instance(VideoTranscoder::class, $this->fake);
        // Only this test's posts take part.
        DB::table('tbl_post')->whereIn('video_status', [1, 4])->update(['video_status' => 0]);
    }

    private function videoPost(int $status, array $extra = []): int
    {
        $user = $this->makeUser();
        return DB::table('tbl_post')->insertGetId(array_merge([
            'user_id' => $user->id, 'post_type' => 1, 'video' => 'uploads/v.mp4',
            'video_status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function status(int $id): int
    {
        return (int) DB::table('tbl_post')->where('id', $id)->value('video_status');
    }

    public function test_upload_job_only_queues(): void
    {
        $id = $this->videoPost(0);

        (new TranscodeVideoJob($id))->handle();

        $this->assertSame(TranscodeVideoJob::STATUS_QUEUED, $this->status($id));
        $this->assertSame([], $this->fake->launched);
    }

    public function test_one_encode_at_a_time_oldest_first(): void
    {
        $first = $this->videoPost(TranscodeVideoJob::STATUS_QUEUED);
        $second = $this->videoPost(TranscodeVideoJob::STATUS_QUEUED);

        $this->artisan('videos:poll-transcode-status')->assertExitCode(0);
        $this->artisan('videos:poll-transcode-status')->assertExitCode(0);

        $this->assertSame([$first], $this->fake->launched, 'the second waits while the first is encoding');
        $this->assertSame(1, $this->status($first));
        $this->assertSame(TranscodeVideoJob::STATUS_QUEUED, $this->status($second));
    }

    public function test_next_starts_once_the_running_one_ends(): void
    {
        $first = $this->videoPost(TranscodeVideoJob::STATUS_QUEUED);
        $second = $this->videoPost(TranscodeVideoJob::STATUS_QUEUED);
        $this->artisan('videos:poll-transcode-status')->assertExitCode(0);

        // The first encode dies without output: it fails, and the queue moves on.
        $this->fake->alive = [];
        $this->artisan('videos:poll-transcode-status')->assertExitCode(0);

        $this->assertSame(3, $this->status($first));
        $this->assertSame([$first, $second], $this->fake->launched);
        $this->assertSame(1, $this->status($second));
    }

    public function test_deleted_post_does_not_block_the_queue(): void
    {
        $deleted = $this->videoPost(1, ['transcode_pid' => 4242, 'deleted_at' => now()]);
        $this->fake->alive[4242] = true;
        $next = $this->videoPost(TranscodeVideoJob::STATUS_QUEUED);

        $this->artisan('videos:poll-transcode-status')->assertExitCode(0);

        $this->assertSame(3, $this->status($deleted));
        $this->assertArrayNotHasKey(4242, $this->fake->alive, 'its ffmpeg was killed');
        $this->assertSame([$next], $this->fake->launched);
    }

    public function test_requeue_failed_only_touches_recent_live_video_posts(): void
    {
        $recent = $this->videoPost(3);
        $old = $this->videoPost(3, ['created_at' => now()->subDays(40)]);
        $gone = $this->videoPost(3, ['deleted_at' => now()]);

        $this->artisan('videos:requeue-failed', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(3, $this->status($recent));

        $this->artisan('videos:requeue-failed')->assertExitCode(0);

        $this->assertSame(TranscodeVideoJob::STATUS_QUEUED, $this->status($recent));
        $this->assertSame(3, $this->status($old));
        $this->assertSame(3, $this->status($gone));
    }
}
