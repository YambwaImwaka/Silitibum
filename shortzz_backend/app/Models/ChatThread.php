<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatThread extends Model
{
    use HasFactory;
    public $table = 'chat_threads';

    const STATUS_REQUEST = 'request';
    const STATUS_APPROVED = 'approved';

    // Threads store the pair ordered so (a,b) and (b,a) hit the same row.
    public static function orderedPair($a, $b)
    {
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    public static function between($a, $b)
    {
        [$u1, $u2] = self::orderedPair($a, $b);
        return self::where('user1_id', $u1)->where('user2_id', $u2)->first();
    }

    public function isMember($userId)
    {
        return $this->user1_id == $userId || $this->user2_id == $userId;
    }

    public function otherUserId($me)
    {
        return $this->user1_id == $me ? $this->user2_id : $this->user1_id;
    }

    public function sideOf($userId)
    {
        return $this->user1_id == $userId ? 'user1' : 'user2';
    }

    public function clearedBeforeIdFor($userId)
    {
        return $this->{$this->sideOf($userId) . '_cleared_before_id'};
    }

    // Compact profile embedded in thread/message payloads (the app's AppUser).
    public static function userSummary($user)
    {
        if ($user == null) {
            return null;
        }
        return [
            'id' => $user->id,
            'fullname' => $user->fullname,
            'username' => $user->username,
            'profile_photo' => $user->profile_photo,
            'is_verify' => $user->is_verify,
        ];
    }

    // Neutral serialization: both sides' state included, the app maps its own
    // perspective by comparing user ids. Events and REST share this shape.
    public function serialize($withUsers = true)
    {
        $data = [
            'id' => $this->id,
            'user1_id' => $this->user1_id,
            'user2_id' => $this->user2_id,
            'initiator_id' => $this->initiator_id,
            'status' => $this->status,
            'last_message_id' => $this->last_message_id,
            'last_msg' => $this->last_msg,
            'last_msg_type' => $this->last_msg_type,
            'last_msg_user_id' => $this->last_msg_user_id,
            'last_msg_at' => $this->last_msg_at,
            'user1_unread_count' => $this->user1_unread_count,
            'user2_unread_count' => $this->user2_unread_count,
            'user1_last_read_message_id' => $this->user1_last_read_message_id,
            'user2_last_read_message_id' => $this->user2_last_read_message_id,
        ];
        if ($withUsers) {
            $data['user1'] = self::userSummary(Users::find($this->user1_id));
            $data['user2'] = self::userSummary(Users::find($this->user2_id));
        }
        return $data;
    }

    // Neutral shape + the viewer-specific block flags REST responses carry.
    public function serializeFor($viewerId)
    {
        $data = $this->serialize();
        $otherId = $this->otherUserId($viewerId);
        $data['i_blocked'] = UserBlocks::where('from_user_id', $viewerId)
            ->where('to_user_id', $otherId)->exists();
        $data['i_am_blocked'] = UserBlocks::where('from_user_id', $otherId)
            ->where('to_user_id', $viewerId)->exists();
        return $data;
    }
}
