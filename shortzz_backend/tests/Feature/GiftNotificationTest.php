<?php

namespace Tests\Feature;

use App\Models\Constants;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

// A gift notification knows which video it was sent on, so tapping it in the
// app can open that video instead of going nowhere.
class GiftNotificationTest extends ApiTestCase
{
    public function test_a_gift_on_a_video_carries_that_video(): void
    {
        $this->setting(['creator_share_percent' => 60]);
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00');
        $postId = DB::table('tbl_post')->insertGetId([
            'post_type' => 1, 'user_id' => $creator->id, 'description' => 'my reel', 'created_at' => now(),
        ]);

        $this->api('misc/sendGift', [
            'user_id' => $creator->id, 'gift_id' => $this->makeGift(100)->id, 'post_id' => $postId,
        ], $this->tokenFor($fan))->assertJsonPath('status', true);

        $response = $this->api('misc/fetchActivityNotifications', ['limit' => 10], $this->tokenFor($creator));
        $gift = collect($response->json('data'))->firstWhere('type', Constants::notify_gift_user);

        $this->assertNotNull($gift, 'the gift notification is listed');
        $this->assertEquals($postId, $gift['data']['post']['id']);
        $this->assertNotNull($gift['data']['gift']);
    }

    public function test_a_gift_sent_elsewhere_still_lists_with_its_gift(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00');

        $this->api('misc/sendGift', [
            'user_id' => $creator->id, 'gift_id' => $this->makeGift(100)->id,
        ], $this->tokenFor($fan))->assertJsonPath('status', true);

        $response = $this->api('misc/fetchActivityNotifications', ['limit' => 10], $this->tokenFor($creator));
        $gift = collect($response->json('data'))->firstWhere('type', Constants::notify_gift_user);

        $this->assertNotNull($gift);
        $this->assertArrayNotHasKey('post', $gift['data']);
        $this->assertEquals($fan->id, $gift['from_user_id']);
    }
}
