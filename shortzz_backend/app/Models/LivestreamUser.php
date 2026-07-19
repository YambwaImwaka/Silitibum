<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LivestreamUser extends Model
{
    use HasFactory;
    public $table = 'livestream_users';

    const TYPE_HOST = 'HOST';
    const TYPE_CO_HOST = 'CO-HOST';
    const TYPE_AUDIENCE = 'AUDIENCE';
    const TYPE_REQUESTED = 'REQUESTED';
    const TYPE_INVITED = 'INVITED';
    const TYPE_LEFT = 'LEFT';

    public function followersGainedArray(): array
    {
        $ids = json_decode($this->followers_gained ?? '[]', true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    // Shape of lib/model/livestream/livestream_user_state.dart, with the
    // embedded 'user' AppUser payload to seed the client cache.
    public function serialize($user = null)
    {
        return [
            'audio_status' => $this->audio_status,
            'video_status' => $this->video_status,
            'type' => $this->type,
            'user_id' => (int) $this->user_id,
            'live_coin' => (int) $this->live_coin,
            'current_battle_coin' => (int) $this->current_battle_coin,
            'total_battle_coin' => (int) $this->total_battle_coin,
            'followers_gained' => $this->followersGainedArray(),
            'join_stream_time' => $this->join_stream_time !== null ? (int) $this->join_stream_time : 0,
            'user' => GlobalFunction::appUserPayload($user ?? Users::find($this->user_id)),
        ];
    }
}
