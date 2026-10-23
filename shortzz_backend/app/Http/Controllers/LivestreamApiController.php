<?php

namespace App\Http\Controllers;

use App\Events\BaseBroadcastEvent;
use App\Models\Gifts;
use App\Models\GlobalFunction;
use App\Models\Livestream;
use App\Models\LivestreamComment;
use App\Models\LivestreamUser;
use App\Models\Users;
use App\Services\Live\LivestreamLiveness;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $stream->last_seen_at = Carbon::now();
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

    // Guest-open: the Live tab lists streams without a session. Only rooms
    // whose host was seen recently: a `live` row alone is no proof anyone is
    // broadcasting (LivestreamLiveness).
    public function fetchLivestreams(Request $request)
    {
        $streams = LivestreamLiveness::listed()
            ->orderBy('watching_count', 'desc')
            ->orderBy('id', 'desc')
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

    // Guest-open: anyone can watch a stream, matching the Live tab that
    // already lists them without a session. A signed-out viewer gets no
    // participant row and no "joined" line — there is no account to attribute
    // either to — only the watching count and the read-only stream state.
    // Everything that writes into the room (comment, gift, like, co-host)
    // stays behind authorizeUser.
    public function joinLivestream(Request $request)
    {
        $user = GlobalFunction::getUserOrGuest($request->header('authtoken'));
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }
        LivestreamLiveness::noteActor($user, $stream);

        if ($user->id == 0) {
            $stream->watching_count = $stream->watching_count + 1;
            $stream->save();

            BaseBroadcastEvent::fire('livestream.updated',
                ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));

            return $this->fullState($stream, null);
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

    /**
     * Stores a single still frame for a live room.
     *
     * The stream media is never recorded — Zego carries it and it is gone the
     * moment the room closes — so this frame is the only thing that outlives
     * the broadcast. Captured by the host shortly after publishing starts,
     * while the camera is definitely running; capturing at end would race the
     * teardown of the very surface being captured.
     *
     * Only the host may set it, and only once: a later call would let a host
     * swap the retained image for something unrelated to what was broadcast.
     */
    public function uploadLivestreamSnapshot(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'room_id' => 'required',
            'snapshot' => 'required|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }

        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        // Not liveStream(): a snapshot arriving just after the host ends the
        // room should still be kept rather than discarded.
        $stream = Livestream::where('room_id', $request->room_id)->first();
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream not found');
        }
        if ($stream->host_id != $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'only the host can set the snapshot');
        }
        if (!empty($stream->snapshot)) {
            return GlobalFunction::sendSimpleResponse(true, 'snapshot already set');
        }

        $stream->snapshot = GlobalFunction::saveFileAndGivePath($request->file('snapshot'));
        $stream->save();

        return GlobalFunction::sendSimpleResponse(true, 'snapshot saved successfully');
    }

    public function leaveLivestream(Request $request)
    {
        $user = GlobalFunction::getUserOrGuest($request->header('authtoken'));
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(true, 'livestream already ended');
        }

        // Mirror of the guest branch in joinLivestream: a signed-out viewer
        // only ever contributed to the watching count.
        if ($user->id == 0) {
            $stream->watching_count = max(0, $stream->watching_count - 1);
            $stream->save();

            BaseBroadcastEvent::fire('livestream.updated',
                ['livestream' => $stream->serialize(false)], $this->channel($stream->room_id));

            return GlobalFunction::sendSimpleResponse(true, 'left livestream');
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

    // Polling fallback + full snapshot on join. The host's own polls are its
    // heartbeat.
    public function fetchStreamState(Request $request)
    {
        $stream = $this->liveStream($request->room_id);
        if ($stream == null) {
            return GlobalFunction::sendSimpleResponse(false, 'livestream_ended');
        }
        LivestreamLiveness::noteRequest($request, $stream);
        return $this->fullState($stream, $request->after_comment_id);
    }

    // Both serializers below feed GlobalFunction::appUserPayload, which reads
    // exactly these five columns. Selecting * pulled every column of every
    // participant and every comment sender — bio, device_token, password,
    // saved_music_ids and the rest — on a request that each viewer repeats
    // every couple of seconds for the whole stream. Narrowing it cuts both the
    // row width MySQL reads and the memory Laravel hydrates, which is what
    // makes a tighter client poll interval affordable. identity (email/phone)
    // is deliberately absent: this payload reaches signed-out guest viewers.
    private const USER_PAYLOAD_COLUMNS = [
        'id', 'username', 'fullname', 'profile_photo', 'is_verify',
    ];

    private function fullState($stream, $afterCommentId)
    {
        $participants = LivestreamUser::where('livestream_id', $stream->id)
            ->where('type', '!=', LivestreamUser::TYPE_LEFT)->get();
        $users = Users::whereIn('id', $participants->pluck('user_id'))
            ->get(self::USER_PAYLOAD_COLUMNS)->keyBy('id');

        $commentsQuery = LivestreamComment::where('livestream_id', $stream->id);
        if ($afterCommentId !== null && $afterCommentId !== '') {
            $comments = $commentsQuery->where('id', '>', $afterCommentId)
                ->orderBy('id', 'asc')->limit(100)->get();
        } else {
            $comments = $commentsQuery->orderBy('id', 'desc')->limit(50)->get()->reverse()->values();
        }
        $senders = Users::whereIn('id', $comments->pluck('sender_id'))
            ->get(self::USER_PAYLOAD_COLUMNS)->keyBy('id');
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
        LivestreamLiveness::noteActor($user, $stream);

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
        // A stream gift is for someone on stage. Any user id used to be
        // accepted, so this endpoint doubled as "send coins to anyone" while
        // the room showed a gift to a host who never received it.
        $onStage = $receiver->id == $stream->host_id
            || in_array((int) $receiver->id, $stream->coHostIdsArray(), true);
        if (!$onStage) {
            return GlobalFunction::sendSimpleResponse(false, 'gifts here can only go to the host or a co-host');
        }
        $gift = Gifts::find($request->gift_id);
        $error = GlobalFunction::transferGiftCoins($user, $receiver, $gift, ['livestream_id' => $stream->id]);
        if ($error !== null) {
            return GlobalFunction::sendSimpleResponse(false, $error);
        }

        // Track stream earnings on the receiving host/co-host. A relative
        // UPDATE: the read-add-save this replaced lost gifts that landed in
        // the same instant, which is exactly when a battle is closest.
        $participant = LivestreamUser::where('livestream_id', $stream->id)
            ->where('user_id', $receiver->id)->first();
        if ($participant != null) {
            $column = ($stream->type == 'BATTLE' && $stream->battle_type == 'RUNNING')
                ? 'current_battle_coin' : 'live_coin';
            LivestreamUser::where('id', $participant->id)->increment($column, (int) $gift->coin_price);
            $participant->refresh();
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

        LivestreamLiveness::noteActor($user, $stream);

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
        LivestreamLiveness::noteActor($user, $stream);

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
            // Bank the round's coins in one statement per row (SET is applied
            // left to right, so the total takes the round's value before it is
            // zeroed) — a gift landing mid-bank can no longer be wiped out.
            $participants = LivestreamUser::where('livestream_id', $stream->id)
                ->where('current_battle_coin', '>', 0)->get();
            foreach ($participants as $participant) {
                LivestreamUser::where('id', $participant->id)->update([
                    'total_battle_coin' => DB::raw('total_battle_coin + current_battle_coin'),
                    'current_battle_coin' => 0,
                ]);
                $participant->refresh();
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
