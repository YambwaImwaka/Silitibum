<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LivestreamComment extends Model
{
    use HasFactory;
    public $table = 'livestream_comments';

    const TYPE_TEXT = 'TEXT';
    const TYPE_GIFT = 'GIFT';
    const TYPE_REQUEST = 'REQUEST';
    const TYPE_JOINED = 'JOINED';
    const TYPE_JOINED_CO_HOST = 'JOINED_CO_HOST';

    const TYPES = [self::TYPE_TEXT, self::TYPE_GIFT, self::TYPE_REQUEST,
        self::TYPE_JOINED, self::TYPE_JOINED_CO_HOST];

    // Shape of lib/model/livestream/livestream_comment.dart; 'sender_user'
    // is extra payload to seed the client's app-user cache.
    public function serialize($sender = null, $gift = null)
    {
        return [
            'id' => (int) $this->id,
            'sender_id' => (int) $this->sender_id,
            'receiver_id' => $this->receiver_id !== null ? (int) $this->receiver_id : null,
            'comment' => $this->comment,
            'comment_type' => $this->comment_type,
            'gift_id' => $this->gift_id !== null ? (int) $this->gift_id : null,
            'gift' => $this->gift_id !== null
                ? ($gift ?? Gifts::find($this->gift_id))
                : null,
            'sender_user' => GlobalFunction::appUserPayload($sender ?? Users::find($this->sender_id)),
        ];
    }
}
