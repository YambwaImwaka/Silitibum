<?php

namespace App\Services\Live;

use App\Events\BaseBroadcastEvent;
use App\Models\GlobalFunction;
use App\Models\Livestream;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Whether a `live` row is a real broadcast. A host whose app crashed, was
// killed or lost signal never calls endLivestream, so its row stayed `live`
// and the Live tab listed rooms nobody was broadcasting from — for months.
//
// A host shows it is alive in either of two ways:
//  - its app talks to the API about the room: the state poll (every app
//    version polls while the realtime socket is quiet; 1.2.1+ also at least
//    every 20 s), and its own comments, battle and co-host changes;
//  - it is in the room's Pusher presence channel, which every app version
//    joins while live. That is what keeps an older app's busy room listed:
//    there the socket is never quiet long enough for the host to poll.
// A room whose host has not been seen for LISTED_FOR drops out of the list;
// livestreams:reap ends it after REAP_AFTER unless the host is in the
// presence channel.
class LivestreamLiveness
{
    /** Listed while the host was seen within this many seconds. */
    public const LISTED_FOR = 120;

    /** The reaper asks Pusher about rooms quiet for this long — often enough
     * to re-confirm a presence-only host before it drops out of the list. */
    public const CHECK_AFTER = 45;

    /** Ended when unseen this long and not in the presence channel. */
    public const REAP_AFTER = 180;

    /** Ended when unseen this long even though Pusher could not be asked. */
    public const REAP_BLIND_AFTER = 1800;

    /** At most one heartbeat write per room per this many seconds. */
    private const TOUCH_EVERY = 15;

    /** The rooms the Live tab may show. */
    public static function listed(): Builder
    {
        return Livestream::where('status', Livestream::STATUS_LIVE)
            ->where('last_seen_at', '>=', Carbon::now()->subSeconds(self::LISTED_FOR));
    }

    /** The host was just seen alive. */
    public static function seen(Livestream $stream): void
    {
        if (self::isFresh($stream)) {
            return;
        }
        $now = Carbon::now();
        Livestream::where('id', $stream->id)->update(['last_seen_at' => $now]);
        $stream->last_seen_at = $now;
    }

    /** A request about the room counts as the host being seen when it carries
     * the host's token. The token is only resolved when the room is due a
     * heartbeat, so viewers polling every 1.2 s add no lookups while the host
     * keeps the room fresh. */
    public static function noteRequest(Request $request, Livestream $stream): void
    {
        if (self::isFresh($stream)) {
            return;
        }
        $token = $request->header('authtoken');
        if (empty($token)) {
            return;
        }
        $user = GlobalFunction::getUserFromAuthToken($token);
        if ($user != null && (int) $user->id === (int) $stream->host_id) {
            self::seen($stream);
        }
    }

    /** $user acted in the room; counts when it is the host. */
    public static function noteActor($user, Livestream $stream): void
    {
        if ($user != null && (int) $user->id === (int) $stream->host_id) {
            self::seen($stream);
        }
    }

    /** Ends a room whose host is gone, and tells anyone still in it. */
    public static function end(Livestream $stream): bool
    {
        $ended = Livestream::where('id', $stream->id)
            ->where('status', Livestream::STATUS_LIVE)
            ->update(['status' => Livestream::STATUS_ENDED, 'ended_at' => Carbon::now()]);
        if ($ended === 0) {
            return false;
        }
        BaseBroadcastEvent::fire('livestream.ended', ['room_id' => $stream->room_id],
            [new PresenceChannel('livestream.' . $stream->room_id)]);
        LiveKit::closeRoom($stream->room_id);
        return true;
    }

    private static function isFresh(Livestream $stream): bool
    {
        if (empty($stream->last_seen_at)) {
            return false;
        }
        return Carbon::parse($stream->last_seen_at)
            ->gt(Carbon::now()->subSeconds(self::TOUCH_EVERY));
    }
}
