<?php

namespace App\Services\Live;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// LiveKit is the media server for live rooms (it replaced Zego). A LiveKit
// room is named after our room_id and exists only while people are in it.
//
// Who may publish is decided here, not in the app: the host and approved
// co-hosts get tokens that can publish, everyone else can only watch. A
// co-host is promoted mid-stream, so their permission is also changed on the
// live connection (setCanPublish) before the room hears about the promotion.
class LiveKit
{
    /** Long enough for any live; LiveKit refreshes tokens on reconnect. */
    private const TOKEN_TTL = 6 * 3600;

    public static function isConfigured(): bool
    {
        return !empty(config('services.livekit.url'))
            && !empty(config('services.livekit.api_key'))
            && !empty(config('services.livekit.api_secret'));
    }

    public static function url(): string
    {
        return (string) config('services.livekit.url');
    }

    /** A token that joins one room. Hidden participants (the Live tab's
     * preview) are invisible to the room and cannot publish. */
    public static function token(string $room, string $identity, string $name,
                                 bool $canPublish, bool $hidden = false): string
    {
        $now = time();
        return JWT::encode([
            'iss' => config('services.livekit.api_key'),
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now - 10,
            'exp' => $now + self::TOKEN_TTL,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canSubscribe' => true,
                'canPublish' => $canPublish && !$hidden,
                'canPublishData' => false,
                'hidden' => $hidden,
            ],
        ], config('services.livekit.api_secret'), 'HS256');
    }

    /** Lets a participant already in the room start or stop publishing. */
    public static function setCanPublish(string $room, string $identity, bool $canPublish): void
    {
        self::roomService('UpdateParticipant', $room, [
            'room' => $room,
            'identity' => $identity,
            'permission' => [
                'canSubscribe' => true,
                'canPublish' => $canPublish,
                'canPublishData' => false,
            ],
        ]);
    }

    /** Disconnects everyone; viewers' apps treat that as the live ending. */
    public static function closeRoom(string $room): void
    {
        self::roomService('DeleteRoom', $room, ['room' => $room]);
    }

    // Best effort: a LiveKit hiccup must never fail the request that caused
    // it (ending a live, promoting a co-host). Rooms close by themselves once
    // empty, and the app copes with a permission that arrives late.
    private static function roomService(string $method, string $room, array $body): void
    {
        if (!self::isConfigured() || $room === '') {
            return;
        }
        $now = time();
        $token = JWT::encode([
            'iss' => config('services.livekit.api_key'),
            'nbf' => $now - 10,
            'exp' => $now + 60,
            'video' => ['room' => $room, 'roomAdmin' => true, 'roomCreate' => true],
        ], config('services.livekit.api_secret'), 'HS256');
        $base = preg_replace('#^ws(s?)://#', 'http$1://', rtrim(self::url(), '/'));

        try {
            $response = Http::withToken($token)->timeout(3)
                ->post("{$base}/twirp/livekit.RoomService/{$method}", $body);
            // not_found: nobody is connected, so there was nothing to change.
            if ($response->failed() && $response->json('code') !== 'not_found') {
                Log::error("LiveKit {$method} failed for {$room}: " . $response->status() . ' ' . $response->body());
            }
        } catch (\Throwable $e) {
            Log::error("LiveKit {$method} failed for {$room}: " . $e->getMessage());
        }
    }
}
