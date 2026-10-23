<?php

namespace Tests\Feature;

use App\Models\Livestream;
use App\Services\Live\HostPresence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApiTestCase;

// The Live tab lists real broadcasts only: rooms whose host's app died
// without ending them stop being listed and are ended by livestreams:reap.
class LivestreamLivenessTest extends ApiTestCase
{
    public function test_only_rooms_with_a_recently_seen_host_are_listed(): void
    {
        $fresh = $this->makeStream(lastSeen: now()->subSeconds(10));
        $quiet = $this->makeStream(lastSeen: now()->subMinutes(10));
        $neverSeen = $this->makeStream(lastSeen: null);
        $ended = $this->makeStream(lastSeen: now(), status: Livestream::STATUS_ENDED);

        $rooms = $this->listedRooms();

        $this->assertContains($fresh->room_id, $rooms);
        $this->assertNotContains($quiet->room_id, $rooms);
        $this->assertNotContains($neverSeen->room_id, $rooms);
        $this->assertNotContains($ended->room_id, $rooms);
    }

    public function test_a_new_room_is_listed_straight_away(): void
    {
        $host = $this->makeUser();

        $response = $this->api('livestream/createLivestream', ['description' => 'hi'], $this->tokenFor($host));

        $response->assertJsonPath('status', true);
        $this->assertContains($response->json('data.livestream.room_id'), $this->listedRooms());
    }

    public function test_the_hosts_own_poll_is_its_heartbeat(): void
    {
        $stream = $this->makeStream(lastSeen: now()->subMinutes(5));
        $viewer = $this->makeUser();

        $this->api('livestream/fetchStreamState', ['room_id' => $stream->room_id], $this->tokenFor($viewer))
            ->assertJsonPath('status', true);
        $this->api('livestream/fetchStreamState', ['room_id' => $stream->room_id])
            ->assertJsonPath('status', true);
        $this->assertNotContains($stream->room_id, $this->listedRooms(), 'a viewer polling is no sign the host is alive');

        $this->api('livestream/fetchStreamState', ['room_id' => $stream->room_id], $this->tokenFor($stream->host))
            ->assertJsonPath('status', true);
        $this->assertContains($stream->room_id, $this->listedRooms());
    }

    public function test_reaper_ends_rooms_whose_host_left(): void
    {
        $this->fakePresence(false);
        $gone = $this->makeStream(lastSeen: now()->subMinutes(10));
        $ghost = $this->makeStream(lastSeen: null, createdAt: now()->subMonths(2));
        $blip = $this->makeStream(lastSeen: now()->subSeconds(90));

        $this->artisan('livestreams:reap')->assertExitCode(0);

        $this->assertSame(Livestream::STATUS_ENDED, $this->status($gone));
        $this->assertSame(Livestream::STATUS_ENDED, $this->status($ghost));
        $this->assertNotNull(DB::table('livestreams')->where('id', $gone->id)->value('ended_at'));
        // Ninety seconds without a sign is a network blip, not a dead room.
        $this->assertSame(Livestream::STATUS_LIVE, $this->status($blip));
    }

    public function test_reaper_keeps_a_host_that_is_in_the_presence_channel_listed(): void
    {
        $this->fakePresence(true);
        $busyRoom = $this->makeStream(lastSeen: now()->subMinutes(10));

        $this->artisan('livestreams:reap')->assertExitCode(0);

        $this->assertSame(Livestream::STATUS_LIVE, $this->status($busyRoom));
        $this->assertContains($busyRoom->room_id, $this->listedRooms());
    }

    public function test_without_an_answer_from_pusher_only_long_silent_rooms_end(): void
    {
        $this->fakePresence(null);
        $recent = $this->makeStream(lastSeen: now()->subMinutes(10));
        $old = $this->makeStream(lastSeen: now()->subHours(2));

        $this->artisan('livestreams:reap')->assertExitCode(0);

        $this->assertSame(Livestream::STATUS_LIVE, $this->status($recent));
        $this->assertSame(Livestream::STATUS_ENDED, $this->status($old));
    }

    public function test_dry_run_ends_nothing(): void
    {
        $this->fakePresence(false);
        $gone = $this->makeStream(lastSeen: now()->subMinutes(10));

        $this->artisan('livestreams:reap', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(Livestream::STATUS_LIVE, $this->status($gone));
    }

    private function makeStream(?Carbon $lastSeen, string $status = Livestream::STATUS_LIVE, ?Carbon $createdAt = null): object
    {
        $host = $this->makeUser();
        $id = DB::table('livestreams')->insertGetId([
            'room_id' => $host->id . '_' . Str::random(12),
            'host_id' => $host->id,
            'status' => $status,
            'last_seen_at' => $lastSeen,
            'created_at' => $createdAt ?? $lastSeen ?? now()->subHour(),
            'updated_at' => now(),
        ]);
        $stream = DB::table('livestreams')->where('id', $id)->first();
        $stream->host = $host;
        return $stream;
    }

    private function listedRooms(): array
    {
        $response = $this->api('livestream/fetchLivestreams', ['limit' => 50]);
        $response->assertJsonPath('status', true);
        return collect($response->json('data'))->pluck('room_id')->all();
    }

    private function status(object $stream): string
    {
        return (string) DB::table('livestreams')->where('id', $stream->id)->value('status');
    }

    private function fakePresence(?bool $answer): void
    {
        $this->app->instance(HostPresence::class, new class($answer) extends HostPresence {
            public function __construct(private ?bool $answer)
            {
            }

            public function isHostPresent(Livestream $stream): ?bool
            {
                return $this->answer;
            }
        });
    }
}
