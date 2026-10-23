<?php

namespace Tests\Feature\Wallet;

use App\Services\Wallet\WalletLedger;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

class GiftEconomicsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting(['creator_share_percent' => 60]);
    }

    private function gift(object $sender, object $receiver, int $coins, ?int $postId = null)
    {
        $params = ['user_id' => $receiver->id, 'gift_id' => $this->makeGift($coins)->id];
        if ($postId !== null) {
            $params['post_id'] = $postId;
        }
        return $this->api('misc/sendGift', $params, $this->tokenFor($sender));
    }

    public function test_creator_earns_sixty_percent_of_what_the_fan_paid(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00'); // K5 package: K0.01 per coin

        $this->gift($fan, $creator, 100)->assertJson(['status' => true]);

        $fanRow = $this->userRow($fan->id);
        $this->assertSame(400, (int) $fanRow->coin_wallet);
        $this->assertSame('4.0000', $fanRow->coin_wallet_value);
        $this->assertSame('0.6000', $this->earnings($creator->id));
        $this->assertSame(100, (int) $this->userRow($creator->id)->coin_collected_lifetime);
        $this->assertSame(0, (int) $this->userRow($creator->id)->coin_wallet, 'gifts never become the creator\'s coins');

        $rows = DB::table('wallet_transactions')->whereNotNull('group_id')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(1, $rows->pluck('group_id')->unique()->count());
        $this->assertSame(['gift_sent', 'gift_received', 'platform_commission'], $rows->pluck('type')->all());
        $this->assertSame('0.4000', $rows[2]->amount);
        $this->assertNull($rows[2]->user_id);
    }

    public function test_bonus_coins_can_be_gifted_but_pay_nobody(): void
    {
        $farmer = $this->makeUser();
        $accomplice = $this->makeUser();
        WalletLedger::creditCoins($farmer->id, 100, 0, WalletLedger::TYPE_BONUS_REGISTRATION, 'bonus:registration:' . $farmer->id);

        $this->gift($farmer, $accomplice, 100)->assertJson(['status' => true]);

        $this->assertSame('0.0000', $this->earnings($accomplice->id));
        $this->assertSame(100, (int) $this->userRow($accomplice->id)->coin_collected_lifetime);
        $this->assertSame(0, (int) $this->userRow($farmer->id)->coin_wallet);
    }

    public function test_mixed_wallet_spends_at_average_cost(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        WalletLedger::creditCoins($fan->id, 100, 0, WalletLedger::TYPE_BONUS_REGISTRATION, 'bonus:registration:' . $fan->id);
        $this->fundCoins($fan, 500, '5.00');

        $this->gift($fan, $creator, 60)->assertJson(['status' => true]);

        // 60 of 600 coins worth K5 in total = K0.50; 60% of it.
        $this->assertSame('0.3000', $this->earnings($creator->id));
        $this->assertSame('4.5000', $this->userRow($fan->id)->coin_wallet_value);
    }

    public function test_spending_the_last_coins_leaves_no_dust(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->setting(['creator_share_percent' => 100]);
        $this->fundCoins($fan, 3, '1.00');

        foreach ([1, 1, 1] as $coins) {
            $this->gift($fan, $creator, $coins)->assertJson(['status' => true]);
        }

        $this->assertSame('0.0000', $this->userRow($fan->id)->coin_wallet_value);
        $this->assertSame('1.0000', $this->earnings($creator->id));
    }

    public function test_cannot_gift_more_than_the_wallet_holds(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->fundCoins($fan, 50, '1.00');

        $this->gift($fan, $creator, 30)->assertJson(['status' => true]);
        $this->gift($fan, $creator, 30)->assertJson(['status' => false]);

        $this->assertSame(20, (int) $this->userRow($fan->id)->coin_wallet);
        $this->assertSame(3, DB::table('wallet_transactions')->whereNotNull('group_id')->count());
    }

    public function test_gift_on_a_post_is_credited_to_that_post(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $stranger = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00');
        $postId = DB::table('tbl_post')->insertGetId(['user_id' => $creator->id, 'post_type' => 1, 'description' => 'my reel', 'created_at' => now()]);
        $strangersPost = DB::table('tbl_post')->insertGetId(['user_id' => $stranger->id, 'post_type' => 1, 'description' => 'not theirs', 'created_at' => now()]);

        $this->gift($fan, $creator, 10, $postId)->assertJson(['status' => true]);
        $this->gift($fan, $creator, 10, $strangersPost)->assertJson(['status' => true]);

        $received = DB::table('wallet_transactions')->where('type', 'gift_received')->orderBy('id')->pluck('post_id');
        $this->assertEquals([$postId, null], $received->all());
    }
}
