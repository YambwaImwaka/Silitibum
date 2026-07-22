<?php

namespace App\Http\Controllers;

use App\Events\BaseBroadcastEvent;
use App\Models\Gifts;
use App\Models\GlobalFunction;
use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamUser;
use App\Models\Users;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

// Livestream signalling over MySQL + presence-channel broadcasting
// (replaces the Firestore livestreams tree; Zego still carries the media).
// The admin panel's dummy-live CRUD lives in LiveStreamController — untouched.
class LivestreamApiController extends Controller
{
    private function me(Request $request)
    {
        return GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
    }

    private function liveStream($roomId)
    {
        return Livestream::where('room_id', $roomId)
            ->where('status', Livestream::STATUS_LIVE)->first();
    }

    private function channel($roomId)
    {
        return [new PresenceChannel('livestream.' . $roomId)];
    }

    public function createLivestream(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        // A host can only run one live room at a time.
        Livestream::where('host_id', $user->id)
            ->where('status', Livestream::STATUS_LIVE)
            ->update(['status' => Livestream::STATUS_ENDED, 'ended_at' => Carbon::now()]);

        $stream = new Livestream();
        $stream->room_id = $user->id . '_' . Carbon::now()->valueOf();
        $stream->host_id = $user->id;
        $stream->description = GlobalFunction::cleanString($request->description ?? '');
        $stream->type = $request->type ?? 'LIVESTREAM';
        $stream->battle_type = 'INITIATE';
        $stream->battle_duration = (int) ($request->battle_duration ?? 5);
        $stream->is_restrict_to_join = (int) ($request->is_restrict_to_join ?? 0);
        $stream->host_view_id = $request->host_view_id;
        $stream->setCoHostIdsArray([]);
        $stream->save();

        $host = new LivestreamUser();
        $host->livestream_id = $stream->id;
        $host->user_id = $user->id;
        $host->type = LivestreamUser::TYPE_HOST;
        $host->join_stream_time = Carbon::now()->valueOf();
        $host->save();

        return GlobalFunction::sendDataResponse(true, 'livestream created successfully', [
            'livestream' => $stream->serialize(),
            'users' => [$host->serialize($user)],
        ]);
    }

    // Guest-open: the Live tab lists streams without a session.
    public function fetchLivestreams(Request $request)
    {
        $streams = Livestream::where('status', Livestream::STATUS_LIVE)
            ->orderBy('watching_count', 'desc')
            ->limit((int) ($request->limit ?? 50))
            ->get();

        $hosts = Users::whereIn('id', $streams->pluck('host_id'))->get()->keyBy('id');
        $items = $streams->map(function ($stream) use ($hosts) {
            $data = $stream->serialize(false);
            $data['host_user'] = GlobalFunction::appUserPayload($hosts->get($stream->host_id));
            return $data;
        });

        return GlobalFunction::sendDataResponse(true, 'livestreams fetched successfully', $items);
    }

    public function joinLivestream(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }

        $participant = LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', $user->id)->first();
        if ($participant == null) {
            $participant = new LivestreamUser();
            $participant->livestream_id = $stream->id;
            $participant->user_id = $user->id;
        }
        $firstJoin = ($participant->id == null);
        if ($participant->user_id != $stream->host_id) {
            $participant->type = LivestreamUser::TYPE_AUDIENCE;
        }
        $participant->left_at = null;
        $participant->join_stream_time = Carbon::now()->valueOf();
        $participant->save();

        // First-time joiners get the "joined" line in the comment feed.
        if ($firstJoin && $participant->user_id != $stream->host_id) {
            $joined = new LivestreamComment();
            $joined->livestream_id = $stream->id;
            $joined->sender_id = $user->id;
            $joined->comment_type = LivestreamComment::TYPE_JOINED;
            $joined->save();
            BaseBroadcastEvent::fire('comment.sent',
                ['comment' => $joined->serialize($user)], $this->channel($stream->room_id));
        }

        $stream->watching_count = $stream->watching_count + 1;
        $stream->save();

        BaseBroadcastEvent::fire('livestream.updated',
            ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));

        return $this->fullState($stream, null);
    }

    public function leaveLivestream(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(true, 'livestream already ended');
        }

        LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', $user->id)
            ->update(['type' => LivestreamUser::TYPE_LEFT, 'left_at' => Carbon::now()]);
        // Leaving a co-host slot frees it.
        $coHosts = $stream->coHostIdsArray();
        if (in_array($user->id, $coHosts)) {
            $stream->setCoHostIdsArray(array_diff($coHosts, [$user->id]));
        }
        $stream->watching_count = max(0, $stream->watching_count - 1);
        $stream->save();

        BaseBroadcastEvent::fire('livestream.updated',
            ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));

        return GlobalFunction::sendSimpleResponse(true, 'left livestream');
    }

    // Polling fallback + full snapshot on join.
    public function fetchStreamState(Request $request)
    {
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }
        return $this->fullState($stream, $request->after_comment_id);
    }

    private function fullState($stream, $afterCommentId)
    {
        $participants = LivestreamUser::where('livestream_id', $stream->id)
            ->where('type', '!=', LivestreamUser::TYPE_LEFT)->get();
        $users = Users::whereIn('id', $participants->pluck('user_id'))->get()->keyBy('id');

        $commentsQuery = LivestreamComment::where('livestream_id', $stream->id);
        if ($afterCommentId !== null && $afterCommentId !== '') {
            $comments = $commentsQuery->where('id', '>', $afterCommentId)
                ->orderBy('id', 'asc')->limit(100)->get();
        } else {
            $comments = $commentsQuery->orderBy('id', 'desc')->limit(50)->get()->reverse()->values();
        }
        $senders = Users::whereIn('id', $comments->pluck('sender_id'))->get()->keyBy('id');
        $gifts = Gifts::whereIn('id', $comments->pluck('gift_id')->filter())->get()->keyBy('id');

        return GlobalFunction::sendDataResponse(true, 'stream state fetched', [
            'livestream' => $stream->serialize(),
            'users' => $participants->map(fn ($p) => $p->serialize($users->get($p->user_id))),
            'comments' => $comments->map(fn ($c) => $c->serialize(
                $senders->get($c->sender_id),
                $c->gift_id ? $gifts->get($c->gift_id) : null)),
        ]);
    }

    public function sendComment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'comment_type' => 'nullable|in:' . implode(',', LivestreamComment::TYPES),
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }

        $comment = new LivestreamComment();
        $comment->livestream_id = $stream->id;
        $comment->sender_id = $user->id;
        $comment->receiver_id = $request->receiver_id;
        $comment->comment_type = $request->comment_type ?? LivestreamComment::TYPE_TEXT;
        $comment->comment = GlobalFunction::cleanString($request->comment ?? '');
        $comment->save();

        $payload = $comment->serialize($user);
        BaseBroadcastEvent::fire('comment.sent', ['comment' => $payload],
            $this->channel($stream->room_id));

        return GlobalFunction::sendDataResponse(true, 'comment sent', $payload);
    }

    // Hearts are batched client-side (one call per viewer per few seconds).
    public function addLikes(Request $request)
    {
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }
        $count = max(1, min(500, (int) ($request->count ?? 1)));
        Livestream::where('id', $stream->id)->increment('like_count', $count);
        $stream->refresh();

        BaseBroadcastEvent::fire('livestream.updated',
            ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));
        return GlobalFunction::sendSimpleResponse(true, 'likes added');
    }

    public function sendStreamGift(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'receiver_user_id' => 'required|exists:tbl_users,id',
            'gift_id' => 'required|exists:tbl_gifts,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }

        $receiver = Users::find($request->receiver_user_id);
        $gift = Gifts::find($request->gift_id);
        $error = GlobalFunction::transferGiftCoins($user, $receiver, $gift);
        if ($error !== null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }

        // Track stream earnings on the receiving host/co-host.
        $participant = LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', $receiver->id)->first();
        if ($participant != null) {
            if ($stream->type == 'BATTLE' && $stream->battle_type == 'RUNNING') {
                $participant->current_battle_coin += $gift->coin_price;
            } else {
                $participant->live_coin += $gift->coin_price;
            }
            $participant->save();
        }

        $comment = new LivestreamComment();
        $comment->livestream_id = $stream->id;
        $comment->sender_id = $user->id;
        $comment->receiver_id = $receiver->id;
        $comment->comment_type = LivestreamComment::TYPE_GIFT;
        $comment->gift_id = $gift->id;
        $comment->save();

        BaseBroadcastEvent::fire('comment.sent',
            ['comment' => $comment->serialize($user, $gift)], $this->channel($stream->room_id));
        if ($participant != null) {
            BaseBroadcastEvent::fire('user_state.updated',
                ['user_state' => $participant->serialize($receiver)], $this->channel($stream->room_id));
        }

        return GlobalFunction::sendSimpleResponse(true, 'gift sent successfully!');
    }

    // Self-service state changes + host moderation (mute/unmute, co-host
    // request/accept/remove). Broadcasts the new state to the room.
    public function updateUserState(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }

        $targetId = (int) ($request->user_id ?? $user->id);
        if ($targetId != $user->id && $stream->host_id != $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'only the host can change other users');
        }

        $participant = LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', $targetId)->first();
        if ($participant == null) {
            return GlobalFunction::sendSimpleResponse(false, 'user is not in this livestream');
        }

        if ($request->filled('type')) {
            $participant->type = $request->type;
            $coHosts = $stream->coHostIdsArray();
            if ($request->type == LivestreamUser::TYPE_CO_HOST) {
                $coHosts[] = $targetId;
                $stream->setCoHostIdsArray($coHosts);
            } else if (in_array($targetId, $coHosts)) {
                $stream->setCoHostIdsArray(array_diff($coHosts, [$targetId]));
            }
            $stream->save();
        }
        if ($request->filled('audio_status')) {
            $participant->audio_status = $request->audio_status;
        }
        if ($request->filled('video_status')) {
            $participant->video_status = $request->video_status;
        }
        $participant->save();

        BaseBroadcastEvent::fire('user_state.updated',
            ['user_state' => $participant->serialize()], $this->channel($stream->room_id));
        if ($request->filled('type')) {
            BaseBroadcastEvent::fire('livestream.updated',
                ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));
        }

        return GlobalFunction::sendDataResponse(true, 'state updated', $participant->serialize());
    }

    public function updateBattleState(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'battle_type' => 'required|in:INITIATE,WAITING,RUNNING,END',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }
        if ($stream->host_id != $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'only the host can change battle state');
        }

        $stream->battle_type = $request->battle_type;
        if ($request->filled('type')) {
            $stream->type = $request->type; // LIVESTREAM <-> BATTLE
        }
        if ($request->filled('battle_duration')) {
            $stream->battle_duration = (int) $request->battle_duration;
        }
        if ($request->battle_type == 'RUNNING' || $request->battle_type == 'WAITING') {
            // Server clock is the battle countdown authority.
            $stream->battle_created_at = Carbon::now()->valueOf();
        }
        $stream->save();

        if ($request->battle_type == 'END') {
            // Bank the round's coins.
            $participants = LivestreamUser::where('livestream_id', $stream->id)
                ->where('current_battle_coin', '>', 0)->get();
            foreach ($participants as $participant) {
                $participant->total_battle_coin += $participant->current_battle_coin;
                $participant->current_battle_coin = 0;
                $participant->save();
                BaseBroadcastEvent::fire('user_state.updated',
                    ['user_state' => $participant->serialize()], $this->channel($stream->room_id));
            }
        }

        BaseBroadcastEvent::fire('battle.updated',
            ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));

        return GlobalFunction::sendDataResponse(true, 'battle state updated', $stream->serialize(false));
    }

    // Called next to followUser when a viewer follows the host/co-host live.
    public function registerFollowGained(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }
        $participant = LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', (int) $request->user_id)->first();
        if ($participant == null) {
            return GlobalFunction::sendSimpleResponse(false, 'user is not in this livestream');
        }
        $gained = $participant->followersGainedArray();
        if (!in_array($user->id, $gained)) {
            $gained[] = $user->id;
            $participant->followers_gained = json_encode($gained);
            $participant->save();
            BaseBroadcastEvent::fire('user_state.updated',
                ['user_state' => $participant->serialize()], $this->channel($stream->room_id));
        }
        return GlobalFunction::sendSimpleResponse(true, 'follow registered');
    }

    public function endLivestream(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = Livestream::where('room_id', $request->room_id)->first();
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream not found');
        }
        if ($stream->host_id != $user->id && $user->is_moderator != 1) {
            return GlobalFunction::sendSimpleResponse(false, 'only the host can end the livestream');
        }

        $stream->status = Livestream::STATUS_ENDED;
        $stream->ended_at = Carbon::now();
        $stream->save();

        BaseBroadcastEvent::fire('livestream.ended',
            ['room_id' => $stream->room_id], $this->channel($stream->room_id));

        $host = LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', $stream->host_id)->first();

        return GlobalFunction::sendDataResponse(true, 'livestream ended', [
            'duration_ms' => $stream->created_at
                ? Carbon::now()->valueOf() - $stream->created_at->valueOf() : 0,
            'total_coins' => ($host->live_coin ?? 0) + ($host->total_battle_coin ?? 0)
                + ($host->current_battle_coin ?? 0),
            'followers_gained' => $host ? count($host->followersGainedArray()) : 0,
            'like_count' => (int) $stream->like_count,
        ]);
    }
}
