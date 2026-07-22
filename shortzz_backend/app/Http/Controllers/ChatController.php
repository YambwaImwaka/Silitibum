<?php

namespace App\Http\Controllers;

use App\Events\BaseBroadcastEvent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Followers;
use App\Models\GlobalFunction;
use App\Models\UserBlocks;
use App\Models\Users;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// 1:1 chat over MySQL + broadcasting (replaces the Firestore chat).
// All endpoints require authorizeUser; realtime events go to
// private-chat.thread.{id} (open chat screens) and private-user.{id}
// (thread list + unread badge).
class ChatController extends Controller
{
    private function me(Request $request)
    {
        return GlobalFunction::getUserFromAuthToken($request->header('authtoken'));
    }

    public function fetchThreads(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $limit = (int) ($request->limit ?? 20);

        $query = ChatThread::where(function ($q) use ($user) {
            $q->where('user1_id', $user->id)->orWhere('user2_id', $user->id);
        })->whereNotNull('last_msg_at');
        if ($request->filled('last_msg_at')) {
            $query->where('last_msg_at', '<', $request->last_msg_at);
        }
        $threads = $query->orderBy('last_msg_at', 'desc')->limit($limit)->get();

        // Batch the other-side user summaries and block flags (no per-thread queries).
        $otherIds = $threads->map(fn ($t) => $t->otherUserId($user->id))->unique()->values();
        $users = Users::whereIn('id', $otherIds)->get()->keyBy('id');
        $iBlockedIds = UserBlocks::where('from_user_id', $user->id)
            ->whereIn('to_user_id', $otherIds)->pluck('to_user_id')->all();
        $blockedMeIds = UserBlocks::where('to_user_id', $user->id)
            ->whereIn('from_user_id', $otherIds)->pluck('from_user_id')->all();

        $items = $threads->map(function ($t) use ($user, $users, $iBlockedIds, $blockedMeIds) {
            $data = $t->serialize(false);
            $data['user1'] = $t->user1_id == $user->id
                ? ChatThread::userSummary($user)
                : ChatThread::userSummary($users->get($t->user1_id));
            $data['user2'] = $t->user2_id == $user->id
                ? ChatThread::userSummary($user)
                : ChatThread::userSummary($users->get($t->user2_id));
            $otherId = $t->otherUserId($user->id);
            $data['i_blocked'] = in_array($otherId, $iBlockedIds);
            $data['i_am_blocked'] = in_array($otherId, $blockedMeIds);
            return $data;
        });

        // Badge total across ALL my threads (not only this page). Requests
        // from strangers still count — the app shows them in the request tab.
        $totalUnread = (int) ChatThread::where('user1_id', $user->id)->sum('user1_unread_count')
            + (int) ChatThread::where('user2_id', $user->id)->sum('user2_unread_count');

        return GlobalFunction::sendDataResponse(true, 'threads fetched successfully', [
            'threads' => $items,
            'total_unread_count' => $totalUnread,
        ]);
    }

    // Lightweight badge endpoint: thread counts with unread messages, split
    // into normal chats vs pending requests (the dashboard polls this).
    public function fetchUnreadCounts(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $threads = ChatThread::where(function ($q) use ($user) {
            $q->where('user1_id', $user->id)->orWhere('user2_id', $user->id);
        })->get(['id', 'user1_id', 'user2_id', 'initiator_id', 'status',
            'user1_unread_count', 'user2_unread_count']);

        $unreadThreads = 0;
        $requestUnreadThreads = 0;
        foreach ($threads as $t) {
            $myUnread = $t->{$t->sideOf($user->id) . '_unread_count'};
            if ($myUnread > 0) {
                $unreadThreads++;
                if ($t->status == ChatThread::STATUS_REQUEST && $t->initiator_id != $user->id) {
                    $requestUnreadThreads++;
                }
            }
        }
        return GlobalFunction::sendDataResponse(true, 'unread counts fetched', [
            'unread_thread_count' => $unreadThreads,
            'request_unread_thread_count' => $requestUnreadThreads,
        ]);
    }

    public function fetchMessages(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        // Accept thread_id, or other_user_id when the app opens a chat from a
        // profile and no thread exists yet (returns an empty page then).
        $thread = null;
        if ($request->filled('thread_id')) {
            $thread = ChatThread::find($request->thread_id);
        } else if ($request->filled('other_user_id')) {
            $thread = ChatThread::between($user->id, (int) $request->other_user_id);
            if ($thread == null) {
                return GlobalFunction::sendDataResponse(true, 'no thread yet', [
                    'messages' => [],
                    'thread' => null,
                ]);
            }
        } else {
            return GlobalFunction::sendSimpleResponse(false, 'thread_id or other_user_id is required');
        }
        if ($thread == null || !$thread->isMember($user->id)) {
            return GlobalFunction::sendSimpleResponse(false, 'thread not found');
        }

        $limit = (int) ($request->limit ?? 30);
        $side = $thread->sideOf($user->id);
        $query = ChatMessage::where('thread_id', $thread->id)
            ->where('id', '>', $thread->clearedBeforeIdFor($user->id))
            ->where('deleted_by_' . $side, 0);

        if ($request->filled('after_message_id')) {
            // Polling mode: everything newer than what the app has.
            $messages = $query->where('id', '>', $request->after_message_id)
                ->orderBy('id', 'asc')->limit($limit)->get();
        } else {
            // History mode: newest first, older-than cursor.
            if ($request->filled('last_message_id')) {
                $query->where('id', '<', $request->last_message_id);
            }
            $messages = $query->orderBy('id', 'desc')->limit($limit)->get();
        }

        return GlobalFunction::sendDataResponse(true, 'messages fetched successfully', [
            'messages' => $messages->map(fn ($m) => $m->serialize()),
            'thread' => $thread->serializeFor($user->id),
        ]);
    }

    public function sendMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message_type' => 'required|in:' . implode(',', ChatMessage::TYPES),
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => $validator->errors()->first()]);
        }
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }

        // Resolve the receiver from an explicit id or an existing thread.
        $thread = null;
        if ($request->filled('thread_id')) {
            $thread = ChatThread::find($request->thread_id);
            if ($thread == null || !$thread->isMember($user->id)) {
                return GlobalFunction::sendSimpleResponse(false, 'thread not found');
            }
            $receiverId = $thread->otherUserId($user->id);
        } else if ($request->filled('receiver_id')) {
            $receiverId = (int) $request->receiver_id;
            $thread = ChatThread::between($user->id, $receiverId);
        } else {
            return GlobalFunction::sendSimpleResponse(false, 'receiver_id or thread_id is required');
        }
        if ($receiverId == $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'you can not message yourself');
        }
        $receiver = Users::find($receiverId);
        if ($receiver == null) {
            return GlobalFunction::sendSimpleResponse(false, 'User not found!');
        }
        if (GlobalFunction::checkUserBlock($user->id, $receiverId)) {
            return GlobalFunction::sendSimpleResponse(false, 'blocked');
        }

        $message = null;
        DB::transaction(function () use ($request, $user, $receiver, &$thread, &$message) {
            if ($thread == null) {
                [$u1, $u2] = ChatThread::orderedPair($user->id, $receiver->id);
                $thread = new ChatThread();
                $thread->user1_id = $u1;
                $thread->user2_id = $u2;
                $thread->initiator_id = $user->id;
                // Instantly approved when the receiver already follows the
                // sender; otherwise it lands in their requests tab.
                $receiverFollowsSender = Followers::where('from_user_id', $receiver->id)
                    ->where('to_user_id', $user->id)->exists();
                $thread->status = $receiverFollowsSender
                    ? ChatThread::STATUS_APPROVED
                    : ChatThread::STATUS_REQUEST;
                $thread->save();
            } else if ($thread->status == ChatThread::STATUS_REQUEST
                && $thread->initiator_id != $user->id) {
                // Replying to a request implies accepting it.
                $thread->status = ChatThread::STATUS_APPROVED;
            }

            $message = new ChatMessage();
            $message->thread_id = $thread->id;
            $message->sender_id = $user->id;
            $message->message_type = $request->message_type;
            $message->text_message = $request->text_message;
            $message->image_message = $request->image_message;
            $message->video_message = $request->video_message;
            $message->audio_message = $request->audio_message;
            $message->wave_data = $request->wave_data;
            $message->post_message = $request->post_message;
            $message->story_reply_message = $request->story_reply_message;
            $message->save();

            $thread->last_message_id = $message->id;
            $thread->last_msg = $message->previewText();
            $thread->last_msg_type = $message->message_type;
            $thread->last_msg_user_id = $user->id;
            $thread->last_msg_at = now()->valueOf();
            $receiverColumn = $thread->sideOf($receiver->id) . '_unread_count';
            $thread->$receiverColumn = $thread->$receiverColumn + 1;
            // Sending again un-clears the sender's own view of the thread.
            $thread->save();
        });

        $threadData = $thread->serialize();
        BaseBroadcastEvent::fire('message.sent',
            ['message' => $message->serialize(), 'thread' => $threadData],
            [
                new PrivateChannel('chat.thread.' . $thread->id),
                new PrivateChannel('user.' . $receiver->id),
            ]);

        // notification_data is a JSON string — the app's FCM handler parses it
        // to route the tap to the right thread.
        GlobalFunction::initiatePushNotification(
            $receiver->notify_chat == 1 && !empty($receiver->device_token),
            true,
            $receiver,
            $user->fullname,
            $message->previewText() ?? '…',
            [
                'type' => 'chat',
                'notification_data' => json_encode([
                    'thread_id' => $thread->id,
                    'sender_id' => $user->id,
                ]),
            ]
        );

        return GlobalFunction::sendDataResponse(true, 'message sent successfully', [
            'message' => $message->serialize(),
            'thread' => $thread->serializeFor($user->id),
        ]);
    }

    public function markThreadRead(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $thread = ChatThread::find($request->thread_id);
        if ($thread == null || !$thread->isMember($user->id)) {
            return GlobalFunction::sendSimpleResponse(false, 'thread not found');
        }
        $side = $thread->sideOf($user->id);
        $thread->{$side . '_unread_count'} = 0;
        $thread->{$side . '_last_read_message_id'} = $thread->last_message_id ?? 0;
        $thread->save();

        BaseBroadcastEvent::fire('thread.updated', ['thread' => $thread->serialize()], [
            new PrivateChannel('chat.thread.' . $thread->id),
            new PrivateChannel('user.' . $thread->otherUserId($user->id)),
        ]);
        return GlobalFunction::sendDataResponse(true, 'thread marked read', $thread->serializeFor($user->id));
    }

    public function acceptChatRequest(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $thread = ChatThread::find($request->thread_id);
        if ($thread == null || !$thread->isMember($user->id)) {
            return GlobalFunction::sendSimpleResponse(false, 'thread not found');
        }
        if ($thread->initiator_id == $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'only the receiver can accept a request');
        }
        $thread->status = ChatThread::STATUS_APPROVED;
        $thread->save();

        BaseBroadcastEvent::fire('thread.updated', ['thread' => $thread->serialize()], [
            new PrivateChannel('chat.thread.' . $thread->id),
            new PrivateChannel('user.' . $thread->otherUserId($user->id)),
        ]);
        return GlobalFunction::sendDataResponse(true, 'request accepted', $thread->serializeFor($user->id));
    }

    public function rejectChatRequest(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $thread = ChatThread::find($request->thread_id);
        if ($thread == null || !$thread->isMember($user->id)) {
            return GlobalFunction::sendSimpleResponse(false, 'thread not found');
        }
        if ($thread->initiator_id == $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'only the receiver can reject a request');
        }
        $otherId = $thread->otherUserId($user->id);
        $threadId = $thread->id;
        ChatMessage::where('thread_id', $threadId)->delete();
        $thread->delete();

        BaseBroadcastEvent::fire('thread.deleted', ['thread_id' => $threadId], [
            new PrivateChannel('chat.thread.' . $threadId),
            new PrivateChannel('user.' . $otherId),
        ]);
        return GlobalFunction::sendSimpleResponse(true, 'request rejected');
    }

    public function unsendMessage(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $message = ChatMessage::find($request->message_id);
        if ($message == null || $message->sender_id != $user->id) {
            return GlobalFunction::sendSimpleResponse(false, 'message not found');
        }
        $message->is_unsent = 1;
        $message->save();

        $thread = $message->thread;
        if ($thread != null && $thread->last_message_id == $message->id) {
            $thread->last_msg = null;
            $thread->save();
        }

        if ($thread != null) {
            BaseBroadcastEvent::fire('message.unsent',
                ['message_id' => $message->id, 'thread' => $thread->serialize()],
                [
                    new PrivateChannel('chat.thread.' . $thread->id),
                    new PrivateChannel('user.' . $thread->otherUserId($user->id)),
                ]);
        }
        return GlobalFunction::sendSimpleResponse(true, 'message unsent');
    }

    public function deleteMessageForMe(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $message = ChatMessage::find($request->message_id);
        if ($message == null) {
            return GlobalFunction::sendSimpleResponse(false, 'message not found');
        }
        $thread = $message->thread;
        if ($thread == null || !$thread->isMember($user->id)) {
            return GlobalFunction::sendSimpleResponse(false, 'message not found');
        }
        $message->{'deleted_by_' . $thread->sideOf($user->id)} = 1;
        $message->save();
        return GlobalFunction::sendSimpleResponse(true, 'message deleted');
    }

    // Per-side clear: hides the history for me only (the other side keeps it).
    public function deleteThread(Request $request)
    {
        $user = $this->me($request);
        if ($user->is_freez == 1) {
            return ['status' => false, 'message' => "this user is freezed!"];
        }
        $thread = ChatThread::find($request->thread_id);
        if ($thread == null || !$thread->isMember($user->id)) {
            return GlobalFunction::sendSimpleResponse(false, 'thread not found');
        }
        $side = $thread->sideOf($user->id);
        $latestId = ChatMessage::where('thread_id', $thread->id)->max('id') ?? 0;
        $thread->{$side . '_cleared_before_id'} = max($thread->last_message_id ?? 0, $latestId);
        $thread->{$side . '_unread_count'} = 0;
        $thread->save();
        return GlobalFunction::sendSimpleResponse(true, 'chat deleted');
    }
}
