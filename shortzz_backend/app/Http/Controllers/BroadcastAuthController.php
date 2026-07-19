<?php

namespace App\Http\Controllers;

use App\Models\ChatThread;
use App\Models\GlobalFunction;
use App\Models\Livestream;
use Illuminate\Http\Request;
use Pusher\Pusher;

// Channel authorization for the Pusher-protocol realtime layer. The app does
// not use Laravel guards/Sanctum, so Broadcast::routes() is unusable — this
// authorizes against the custom authtoken header instead. The HMAC response
// format is identical for Pusher cloud, Soketi and Reverb, so the transport
// can be swapped via .env without touching this code.
class BroadcastAuthController extends Controller
{
    public function authenticate(Request $request)
    {
        $user = GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
        if ($user == null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $channel = (string) $request->input('channel_name');
        $socketId = (string) $request->input('socket_id');
        if ($channel === '' || $socketId === '') {
            return response()->json(['message' => 'Bad request'], 400);
        }

        $config = config('broadcasting.connections.pusher');
        $pusher = new Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options'] ?? []
        );

        // private-user.{id}: only the owner.
        if (preg_match('/^private-user\.(\d+)$/', $channel, $m)) {
            if ((int) $m[1] !== (int) $user->id) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            return response($pusher->authorizeChannel($channel, $socketId))
                ->header('Content-Type', 'application/json');
        }

        // private-chat.thread.{id}: thread members only.
        if (preg_match('/^private-chat\.thread\.(\d+)$/', $channel, $m)) {
            $thread = ChatThread::find((int) $m[1]);
            if ($thread == null || !$thread->isMember($user->id)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            return response($pusher->authorizeChannel($channel, $socketId))
                ->header('Content-Type', 'application/json');
        }

        // presence-livestream.{roomId}: any signed-in user while the stream
        // is live (member info feeds watcher lists / comment avatars).
        if (preg_match('/^presence-livestream\.([\w\-]+)$/', $channel, $m)) {
            $stream = Livestream::where('room_id', $m[1])->first();
            if ($stream == null || $stream->status != 'live') {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $userInfo = ChatThread::userSummary($user);
            return response($pusher->authorizePresenceChannel(
                    $channel, $socketId, (string) $user->id, $userInfo))
                ->header('Content-Type', 'application/json');
        }

        return response()->json(['message' => 'Forbidden'], 403);
    }
}
