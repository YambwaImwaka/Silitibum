<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostComments extends Model
{
    use HasFactory;
    // Deleted comments are retained for the same window as posts — a comment
    // is exactly the kind of content a takedown gets argued about later, and
    // hard-deleting it destroyed the only record of what was said.
    use SoftDeletes;

    public $table = "tbl_comments";

    // See Posts::$casts — the retention deadline is rendered as a date in the
    // admin trash view and is not one of the columns Eloquent casts for free.
    protected $casts = [
        'purge_after' => 'datetime',
    ];

    public function post()
    {
        return $this->hasOne(Posts::class, 'id', 'post_id');
    }
    public function user()
    {
        return $this->hasOne(Users::class, 'id', 'user_id');
    }
    public function likes()
    {
        return $this->hasMany(CommentLikes::class, 'comment_id', 'id');
    }
    public function replies()
    {
        return $this->hasMany(CommentReplies::class, 'comment_id', 'id');
    }
}
