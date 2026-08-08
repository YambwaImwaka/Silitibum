<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommentReplies extends Model
{
    use HasFactory;
    // Retained on delete, same as comments and posts.
    use SoftDeletes;

    public $table = "comment_replies";

    // See Posts::$casts.
    protected $casts = [
        'purge_after' => 'datetime',
    ];

    public function comment()
    {
        return $this->hasOne(PostComments::class, 'id', 'comment_id');
    }
    public function user()
    {
        return $this->hasOne(Users::class, 'id', 'user_id');
    }
}
