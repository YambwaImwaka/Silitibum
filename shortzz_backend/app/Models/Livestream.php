<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Livestream extends Model
{
    use HasFactory;
    public $table = 'livestreams';

    const STATUS_LIVE = 'live';
    const STATUS_ENDED = 'ended';

    public function participants()
    {
        return $this->hasMany(LivestreamUser::class, 'livestream_id');
    }

    public function coHostIdsArray(): array
    {
        $ids = json_decode($this->co_host_ids ?? '[]', true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    public function setCoHostIdsArray(array $ids): void
    {
        $this->co_host_ids = json_encode(array_values(array_unique(array_map('intval', $ids))));
    }

    // Keys/values match lib/model/livestream/livestream.dart exactly —
    // including the dashed 'co-host_ids' key and epoch-ms timestamps.
    // 'host_user' is extra (ignored by Livestream.fromJson, used to seed the
    // app-user cache).
    public function serialize($withHostUser = true)
    {
        $data = [
            'watching_count' => (int) $this->watching_count,
            'description' => $this->description,
            'type' => $this->type,
            'battle_type' => $this->battle_type,
            'battle_duration' => (int) $this->battle_duration,
            'is_restrict_to_join' => (int) $this->is_restrict_to_join,
            'host_view_id' => $this->host_view_id,
            'room_id' => $this->room_id,
            'like_count' => (int) $this->like_count,
            'host_id' => (int) $this->host_id,
            'co-host_ids' => $this->coHostIdsArray(),
            'created_at' => $this->created_at ? (int) $this->created_at->valueOf() : 0,
            'battle_created_at' => $this->battle_created_at !== null ? (int) $this->battle_created_at : null,
            'is_dummy_live' => 0,
            'dummy_user_link' => '',
        ];
        if ($withHostUser) {
            $data['host_user'] = GlobalFunction::appUserPayload(Users::find($this->host_id));
        }
        return $data;
    }
}
