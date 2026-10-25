<?php

namespace Tests\Feature;

use App\Models\LivestreamUser;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestCase;

// Live video runs on LiveKit. The app only ever holds a token for one room,
// and only the host and approved co-hosts may publish into it.
class LiveKitTokenTest extends ApiTestCase
{
    private const SECRET = 'test-livekit-secret-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.livekit.url' => 'wss://silitibum-test.livekit.cloud',
            'services.livekit.api_key' => 'APItest',
            'services.livekit.api_secret' => self::SECRET,
        ]);
        Http::fake(['*' => Http::response([], 200)]);
    }

    public function test_the_host_gets_a_token_that_publishes_into_their_room(): void
    {
        $host = $this->makeUser();
        $roomId = $this->api('livestream/createLivestream', ['description' => 'hi'], $this->tokenFor($host))
            ->json('data.livestream.room_id');

        $response = $this->api('livestream/fetchRtcToken', ['room_id' => $roomId], $this->tokenFor($host));

        $response->assertJsonPath('status', true)
            ->assertJsonPath('data.url', 'wss://silitibum-test.livekit.cloud')
            ->assertJsonPath('data.identity', (string) $host->id)
            ->assertJsonPath('data.can_publish', true);
        $claims = $this->claims($response->json('data.token'));
        $this->assertSame('APItest', $claims->iss);
        $this->assertSame((string) $host->id, $claims->sub);
        $this->assertSame($roomId, $claims->video->room);
        $this->assertTrue($claims->video->roomJoin);
        $this->assertTrue($claims->video->canPublish);
    }

    public function test_viewers_and_guests_can_only_watch(): void
    {
        $roomId = $this->liveRoom();
        $viewer = $this->makeUser();
        // withHeaders() keeps the last authtoken; start this request clean.
        $this->flushHeaders();

        $guest = $this->api('livestream/fetchRtcToken', ['room_id' => $roomId, 'guest_id' => 'g17298<script>']);
        $guest->assertJsonPath('status', true)->assertJsonPath('data.identity', 'guest_g17298script');
        $this->assertFalse($this->claims($guest->json('data.token'))->video->canPublish);

        $signedIn = $this->api('livestream/fetchRtcToken', ['room_id' => $roomId], $this->tokenFor($viewer));
        $signedIn->assertJsonPath('data.identity', (string) $viewer->id);
        $this->assertFalse($this->claims($signedIn->json('data.token'))->video->canPublish);
    }

    public function test_a_preview_is_hidden_and_never_publishes(): void
    {
        $host = $this->makeUser();
        $roomId = $this->api('livestream/createLivestream', [], $this->tokenFor($host))->json('data.livestream.room_id');

        $preview = $this->api('livestream/fetchRtcToken', ['room_id' => $roomId, 'preview' => 1], $this->tokenFor($host));

        $video = $this->claims($preview->json('data.token'))->video;
        $this->assertTrue($video->hidden);
        $this->assertFalse($video->canPublish);
    }

    public function test_ended_rooms_and_a_missing_setup_give_no_token(): void
    {
        $host = $this->makeUser();
        $token = $this->tokenFor($host);
        $roomId = $this->api('livestream/createLivestream', [], $token)->json('data.livestream.room_id');
        $this->api('livestream/endLivestream', ['room_id' => $roomId], $token);

        $this->api('livestream/fetchRtcToken', ['room_id' => $roomId], $token)
            ->assertJsonPath('status', false)->assertJsonPath('message', 'livestream_ended');

        config(['services.livekit.api_secret' => null]);
        $this->api('livestream/fetchRtcToken', ['room_id' => $this->liveRoom()])
            ->assertJsonPath('status', false)->assertJsonPath('message', 'live_unavailable');
    }

    public function test_a_co_host_may_publish_from_the_moment_they_are_promoted(): void
    {
        $host = $this->makeUser();
        $hostToken = $this->tokenFor($host);
        $roomId = $this->api('livestream/createLivestream', [], $hostToken)->json('data.livestream.room_id');
        $guest = $this->makeUser();
        $guestToken = $this->tokenFor($guest);
        $this->api('livestream/joinLivestream', ['room_id' => $roomId], $guestToken);

        $this->api('livestream/updateUserState', [
            'room_id' => $roomId, 'user_id' => $guest->id, 'type' => LivestreamUser::TYPE_CO_HOST,
        ], $hostToken)->assertJsonPath('status', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://silitibum-test.livekit.cloud/twirp/livekit.RoomService/UpdateParticipant'
            && $request['room'] === $roomId
            && $request['identity'] === (string) $guest->id
            && $request['permission']['canPublish'] === true);
        $rejoin = $this->api('livestream/fetchRtcToken', ['room_id' => $roomId], $guestToken);
        $this->assertTrue($this->claims($rejoin->json('data.token'))->video->canPublish);

        $this->api('livestream/updateUserState', [
            'room_id' => $roomId, 'user_id' => $guest->id, 'type' => LivestreamUser::TYPE_AUDIENCE,
        ], $hostToken);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/UpdateParticipant')
            && $request['permission']['canPublish'] === false);
    }

    public function test_ending_a_live_closes_its_video_room(): void
    {
        $host = $this->makeUser();
        $token = $this->tokenFor($host);
        $roomId = $this->api('livestream/createLivestream', [], $token)->json('data.livestream.room_id');

        $this->api('livestream/endLivestream', ['room_id' => $roomId], $token)->assertJsonPath('status', true);

        Http::assertSent(fn ($request) => $request->url() === 'https://silitibum-test.livekit.cloud/twirp/livekit.RoomService/DeleteRoom'
            && $request['room'] === $roomId);
    }

    private function liveRoom(): string
    {
        $host = $this->makeUser();
        return $this->api('livestream/createLivestream', [], $this->tokenFor($host))->json('data.livestream.room_id');
    }

    private function claims(?string $jwt): object
    {
        $this->assertNotEmpty($jwt);
        return JWT::decode($jwt, new Key(self::SECRET, 'HS256'));
    }
}
