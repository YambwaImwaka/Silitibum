<?php

namespace App\Services\Notifications;

use App\Models\CommentReplies;
use App\Models\Constants;
use App\Models\PostComments;

// Makes a push open what it is about. Callers of GlobalFunction::pushToUser
// pass the notification's own type number and id; every app version only
// knows how to open `type` post|user|chat|live_stream with
// `notification_data` (a JSON string of ints) — so tapping a like, comment,
// follow or gift push used to open the app and nothing else.
class PushRoute
{
    public static function forApp(array $data): array
    {
        $type = (string) ($data['type'] ?? '');
        $id = (int) ($data['id'] ?? 0);
        $route = null;

        switch ($type) {
            case (string) Constants::notify_like_post:
            case (string) Constants::notify_mention_post:
            case (string) Constants::notify_new_post_from_following:
                $route = ['post', ['id' => $id]];
                break;

            case (string) Constants::notify_comment_post:
            case (string) Constants::notify_mention_comment:
                $comment = PostComments::find($id);
                if ($comment != null) {
                    $route = ['post', ['id' => (int) $comment->post_id, 'comment_id' => $id]];
                }
                break;

            case (string) Constants::notify_reply_comment:
            case (string) Constants::notify_mention_reply:
                $reply = CommentReplies::find($id);
                $comment = $reply ? PostComments::find($reply->comment_id) : null;
                if ($comment != null) {
                    $route = ['post', [
                        'id' => (int) $comment->post_id,
                        'comment_id' => (int) $comment->id,
                        'reply_comment_id' => $id,
                    ]];
                }
                break;

            case (string) Constants::notify_follow_user:
                $route = ['user', ['id' => $id]];
                break;

            case (string) Constants::notify_gift_user:
                // The video it was sent on, else who sent it.
                if (!empty($data['post_id'])) {
                    $route = ['post', ['id' => (int) $data['post_id']]];
                } elseif (!empty($data['from_user_id'])) {
                    $route = ['user', ['id' => (int) $data['from_user_id']]];
                }
                break;
        }

        if ($route === null) {
            return $data;
        }
        [$appType, $info] = $route;
        // The original number stays available to newer apps.
        $data['notify_type'] = $type;
        $data['type'] = $appType;
        $data['notification_data'] = json_encode($info);
        return $data;
    }
}
