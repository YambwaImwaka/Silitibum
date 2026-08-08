<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Posts extends Model
{
    use HasFactory;
    // Deleted posts are retained for a window (see the retention migration and
    // posts:purge-retention) so a takedown can still be evidenced if it is
    // ever disputed. The trait's global `deleted_at IS NULL` scope is exactly
    // what we want here: no listing query anywhere should see a removed post,
    // and the only two places that must are the admin trash view
    // (onlyTrashed) and the purge command (withTrashed).
    use SoftDeletes;

    public $table = "tbl_post";

    // purge_after is read back as a raw string otherwise — only created_at,
    // updated_at and deleted_at are cast for free — and the admin trash view
    // formats it as a date. An uncast column there took the whole listing down.
    protected $casts = [
        'purge_after' => 'datetime',
    ];

    public function user()
    {
        return $this->hasOne(Users::class, 'id', 'user_id');
    }
    public function music()
    {
        return $this->hasOne(Musics::class, 'id', 'sound_id');
    }
    public function images()
    {
        return $this->hasMany(PostImages::class, 'post_id', 'id');
    }
    public function comments()
    {
        return $this->hasMany(PostComments::class, 'post_id', 'id');
    }



}
