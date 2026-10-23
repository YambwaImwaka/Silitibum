<?php

namespace App\Services\Live;

use App\Models\Livestream;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

// Asks Pusher whether a room's host is in its presence channel
// (presence-livestream.{roomId}, joined by the host's app while it is live —
// see BroadcastAuthController). Bound in the container so tests can replace it.
class HostPresence
{
    /** true / false, or null when it cannot be known (broadcasting is not
     * Pusher, or the API call failed) — never a guess either way. */
    public function isHostPresent(Livestream $stream): ?bool
    {
        if (config('broadcasting.default') !== 'pusher') {
            return null;
        }
        $config = config('broadcasting.connections.pusher');
        if (empty($config['key']) || empty($config['secret']) || empty($config['app_id'])) {
            return null;
        }

        try {
            $pusher = new Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                // A slow Pusher must not hold up the per-minute reaper.
                array_merge($config['options'] ?? [], ['timeout' => 5])
            );
            $result = $pusher->getPresenceUsers('presence-livestream.' . $stream->room_id);
            foreach (($result->users ?? []) as $member) {
                if ((string) ($member->id ?? '') === (string) $stream->host_id) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            Log::warning("HostPresence: could not ask Pusher about room {$stream->room_id}: " . $e->getMessage());
            return null;
        }
    }
}
