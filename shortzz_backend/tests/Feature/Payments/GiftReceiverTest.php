<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

class GiftReceiverTest extends ApiTestCase
{
    private function sendGift(object $sender, object $receiver, object $gift)
    {
        return $this->api('misc/sendGift', ['user_id' => $receiver->id, 'gift_id' => $gift->id], $this->tokenFor($sender));
    }

    public function test_gift_moves_coins_between_real_accounts(): void
    {
        $sender = $this->makeUser(['coin_wallet' => 100]);
        $receiver = $this->makeUser();
        $gift = $this->makeGift(30);

        $this->sendGift($sender, $receiver, $gift)->assertJson(['status' => true]);

        $this->assertSame(70, (int) $this->userRow($sender->id)->coin_wallet);
        $this->assertSame(30, (int) $this->userRow($receiver->id)->coin_collected_lifetime);
    }

    public function test_dummy_accounts_cannot_receive_gifts(): void
    {
        $sender = $this->makeUser(['coin_wallet' => 100]);
        $dummy = $this->makeUser(['is_dummy' => 1]);

        $this->sendGift($sender, $dummy, $this->makeGift(30))->assertJson(['status' => false]);

        $this->assertSame(100, (int) $this->userRow($sender->id)->coin_wallet);
    }

    public function test_frozen_accounts_cannot_receive_gifts(): void
    {
        $sender = $this->makeUser(['coin_wallet' => 100]);
        $frozen = $this->makeUser(['is_freez' => 1]);

        $this->sendGift($sender, $frozen, $this->makeGift(30))->assertJson(['status' => false]);

        $this->assertSame(100, (int) $this->userRow($sender->id)->coin_wallet);
    }

    public function test_blocked_users_cannot_gift_each_other(): void
    {
        $sender = $this->makeUser(['coin_wallet' => 100]);
        $receiver = $this->makeUser();
        DB::table('tbl_user_block')->insert(['from_user_id' => $receiver->id, 'to_user_id' => $sender->id]);

        $this->sendGift($sender, $receiver, $this->makeGift(30))->assertJson(['status' => false]);

        $this->assertSame(100, (int) $this->userRow($sender->id)->coin_wallet);
    }

    public function test_stream_gift_must_go_to_someone_on_stage(): void
    {
        $host = $this->makeUser();
        $bystander = $this->makeUser();
        $viewer = $this->makeUser(['coin_wallet' => 100]);
        $gift = $this->makeGift(10);
        $streamId = DB::table('livestreams')->insertGetId([
            'room_id' => $host->id . '_123', 'host_id' => $host->id, 'status' => 'live',
            'co_host_ids' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('livestream_users')->insert(['livestream_id' => $streamId, 'user_id' => $host->id, 'type' => 'HOST', 'created_at' => now()]);
        $token = $this->tokenFor($viewer);

        $this->api('livestream/sendStreamGift', ['room_id' => $host->id . '_123', 'receiver_user_id' => $bystander->id, 'gift_id' => $gift->id], $token)
            ->assertJson(['status' => false]);
        $this->assertSame(100, (int) $this->userRow($viewer->id)->coin_wallet);

        $this->api('livestream/sendStreamGift', ['room_id' => $host->id . '_123', 'receiver_user_id' => $host->id, 'gift_id' => $gift->id], $token)
            ->assertJson(['status' => true]);
        $this->assertSame(90, (int) $this->userRow($viewer->id)->coin_wallet);
        $this->assertSame(10, (int) DB::table('livestream_users')->where('livestream_id', $streamId)->where('user_id', $host->id)->value('live_coin'));
    }
}
