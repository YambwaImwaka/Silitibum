<?php

namespace Tests\Feature;

use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Services\Notifications\PushRoute;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

// Tapping a push opens what it is about. Every app version opens `type`
// post|user|chat|live_stream with `notification_data` (JSON of ints).
class PushRouteTest extends ApiTestCase
{
    public function test_likes_mentions_and_new_posts_open_the_post(): void
    {
        foreach ([Constants::notify_like_post, Constants::notify_mention_post, Constants::notify_new_post_from_following] as $type) {
            $routed = PushRoute::forApp(['type' => (string) $type, 'id' => '42']);
            $this->assertSame('post', $routed['type']);
            $this->assertSame(['id' => 42], json_decode($routed['notification_data'], true));
            $this->assertSame((string) $type, $routed['notify_type']);
        }
    }

    public function test_a_comment_opens_its_post_at_the_comment(): void
    {
        [$postId, $commentId] = $this->postWithComment();

        foreach ([Constants::notify_comment_post, Constants::notify_mention_comment] as $type) {
            $routed = PushRoute::forApp(['type' => (string) $type, 'id' => (string) $commentId]);
            $this->assertSame('post', $routed['type']);
            $this->assertSame(['id' => $postId, 'comment_id' => $commentId], json_decode($routed['notification_data'], true));
        }
    }

    public function test_a_reply_opens_its_post_at_the_reply(): void
    {
        [$postId, $commentId] = $this->postWithComment();
        $replyId = DB::table('comment_replies')->insertGetId([
            'comment_id' => $commentId, 'user_id' => $this->makeUser()->id, 'reply' => 'yes',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([Constants::notify_reply_comment, Constants::notify_mention_reply] as $type) {
            $routed = PushRoute::forApp(['type' => (string) $type, 'id' => (string) $replyId]);
            $this->assertSame('post', $routed['type']);
            $this->assertSame(['id' => $postId, 'comment_id' => $commentId, 'reply_comment_id' => $replyId],
                json_decode($routed['notification_data'], true));
        }
    }

    public function test_a_follow_opens_the_followers_profile(): void
    {
        $routed = PushRoute::forApp(['type' => (string) Constants::notify_follow_user, 'id' => '7']);
        $this->assertSame('user', $routed['type']);
        $this->assertSame(['id' => 7], json_decode($routed['notification_data'], true));
    }

    public function test_a_gift_opens_the_video_it_was_sent_on_or_else_the_sender(): void
    {
        $onPost = PushRoute::forApp(['type' => (string) Constants::notify_gift_user, 'id' => '3', 'from_user_id' => '9', 'post_id' => '55']);
        $this->assertSame('post', $onPost['type']);
        $this->assertSame(['id' => 55], json_decode($onPost['notification_data'], true));

        $elsewhere = PushRoute::forApp(['type' => (string) Constants::notify_gift_user, 'id' => '3', 'from_user_id' => '9', 'post_id' => '']);
        $this->assertSame('user', $elsewhere['type']);
        $this->assertSame(['id' => 9], json_decode($elsewhere['notification_data'], true));
    }

    public function test_pushes_the_app_already_understands_are_left_alone(): void
    {
        $chat = ['type' => 'chat', 'notification_data' => '{"thread_id":4}'];
        $this->assertSame($chat, PushRoute::forApp($chat));
        $this->assertSame(['type' => 'verification'], PushRoute::forApp(['type' => 'verification']));
        // A comment that is gone has nothing to open: unchanged rather than broken.
        $this->assertSame('2', PushRoute::forApp(['type' => '2', 'id' => '999999999'])['type']);
    }

    public function test_the_routed_push_is_valid_fcm_data(): void
    {
        $payload = GlobalFunction::generatePushNotificationPayload(
            Constants::android, Constants::pushTypeToken, 'device-token', 'Mwila', 'liked your video', null,
            PushRoute::forApp(['type' => (string) Constants::notify_like_post, 'id' => '42'])
        );

        foreach ($payload['message']['data'] as $value) {
            $this->assertIsString($value);
        }
        $this->assertSame('post', $payload['message']['data']['type']);
    }

    private function postWithComment(): array
    {
        $owner = $this->makeUser();
        $postId = DB::table('tbl_post')->insertGetId([
            'post_type' => 1, 'user_id' => $owner->id, 'description' => 'reel', 'created_at' => now(),
        ]);
        $commentId = DB::table('tbl_comments')->insertGetId([
            'post_id' => $postId, 'user_id' => $this->makeUser()->id, 'comment' => 'nice', 'created_at' => now(),
        ]);
        return [$postId, $commentId];
    }
}
