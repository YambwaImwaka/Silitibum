<?php

namespace Tests\Feature\Wallet;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

class LedgerCommandsTest extends ApiTestCase
{
    private function topup(object $user, int $coins, float $amount): void
    {
        DB::table('tbl_coin_topup_request')->insert([
            'user_id' => $user->id, 'coin_package_id' => 9, 'coins' => $coins, 'amount' => $amount,
            'phone' => '260971234567', 'provider' => 'lenco', 'status' => 'completed',
            'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20),
        ]);
    }

    /// Shapes taken from production on 2026-10-18 (coin_value K0.05, bonus off).
    private function productionLikeUsers(): array
    {
        $this->setting(['coin_value' => 0.05, 'registration_bonus_status' => 0, 'registration_bonus_amount' => 100]);
        // Bought 510 coins for K6 and gifted 230 of them away.
        $buyer = $this->makeUser(['coin_wallet' => 280, 'coin_purchased_lifetime' => 510, 'coin_collected_lifetime' => 0, 'coin_gifted_lifetime' => 230]);
        $this->topup($buyer, 500, 5.00);
        $this->topup($buyer, 10, 1.00);
        // Only ever received gifts.
        $creator = $this->makeUser(['coin_wallet' => 55, 'coin_collected_lifetime' => 55]);
        // Bought 10, received 20, gifted 5.
        $mixed = $this->makeUser(['coin_wallet' => 25, 'coin_purchased_lifetime' => 10, 'coin_collected_lifetime' => 20, 'coin_gifted_lifetime' => 5]);
        $this->topup($mixed, 10, 1.00);
        return [$buyer, $creator, $mixed];
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$buyer, $creator] = $this->productionLikeUsers();

        $this->artisan('wallet:migrate-earnings', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(55, (int) $this->userRow($creator->id)->coin_wallet);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_migration_pays_received_coins_at_sixty_percent_of_what_buyers_paid(): void
    {
        [$buyer, $creator, $mixed] = $this->productionLikeUsers();

        $this->artisan('wallet:migrate-earnings')->assertExitCode(0);

        // Buyers paid K7 for 520 coins before the ledger; 60% of that is
        // K0.0080 per received coin (not the old K0.05 coin_value). Bought
        // coins stay spendable, valued at what was paid (K6 for 510 coins).
        $creatorRow = $this->userRow($creator->id);
        $this->assertSame(0, (int) $creatorRow->coin_wallet);
        $this->assertSame('0.4400', $creatorRow->earnings_balance);

        $buyerRow = $this->userRow($buyer->id);
        $this->assertSame(280, (int) $buyerRow->coin_wallet);
        $this->assertSame('3.2941', $buyerRow->coin_wallet_value);
        $this->assertSame('0.0000', $buyerRow->earnings_balance);

        $mixedRow = $this->userRow($mixed->id);
        $this->assertSame(5, (int) $mixedRow->coin_wallet);
        $this->assertSame('0.5000', $mixedRow->coin_wallet_value);
        $this->assertSame('0.1600', $mixedRow->earnings_balance);

        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }

    public function test_migration_is_safe_to_run_twice(): void
    {
        [, $creator] = $this->productionLikeUsers();

        $this->artisan('wallet:migrate-earnings')->assertExitCode(0);
        $this->artisan('wallet:migrate-earnings')->assertExitCode(0);

        $this->assertSame('0.4400', $this->userRow($creator->id)->earnings_balance);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $creator->id)->count());
    }

    public function test_gift_received_before_migration_does_not_inflate_conversion(): void
    {
        [$buyer] = $this->productionLikeUsers();
        $fan = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00');
        // The new code went live first: the buyer receives a 100-coin gift
        // (paid as earnings), which also bumps coin_collected_lifetime.
        $this->api('misc/sendGift', ['user_id' => $buyer->id, 'gift_id' => $this->makeGift(100)->id], $this->tokenFor($fan))->assertJson(['status' => true]);

        $this->artisan('wallet:migrate-earnings')->assertExitCode(0);

        // The buyer's 280 bought coins must not be turned into K14 of cash.
        $row = $this->userRow($buyer->id);
        $this->assertSame(280, (int) $row->coin_wallet);
        $this->assertSame('0.6000', $row->earnings_balance);
        $this->artisan('wallet:reconcile')->assertExitCode(0);
    }

    public function test_reconcile_catches_a_balance_changed_outside_the_ledger(): void
    {
        $fan = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00');
        $this->artisan('wallet:reconcile')->assertExitCode(0);

        DB::table('tbl_users')->where('id', $fan->id)->update(['coin_wallet' => 999]);

        $this->artisan('wallet:reconcile')->assertExitCode(1);
    }

    public function test_creator_history_api_is_private_and_complete(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->fundCoins($fan, 500, '5.00');
        $this->api('misc/sendGift', ['user_id' => $creator->id, 'gift_id' => $this->makeGift(100)->id], $this->tokenFor($fan))->assertJson(['status' => true]);

        $data = $this->api('misc/fetchWalletTransactions', ['limit' => 20], $this->tokenFor($creator))->assertJson(['status' => true])->json('data');

        $this->assertSame('0.60', $data['wallet']['earnings_balance']);
        $this->assertCount(1, $data['transactions']);
        $tx = $data['transactions'][0];
        $this->assertSame('gift_received', $tx['type']);
        $this->assertSame('0.60', $tx['amount']);
        $this->assertSame($fan->id, $tx['counterparty']['id']);
        $this->assertArrayNotHasKey('idempotency_key', $tx);
        $this->assertArrayNotHasKey('identity', $tx['counterparty']);
        // The platform's commission row is never part of anyone's history.
        $this->assertSame(0, collect($data['transactions'])->where('type', 'platform_commission')->count());
    }

    public function test_tester_admins_cannot_move_money(): void
    {
        DB::table('tbl_admin')->where('id', 2)->update(['admin_password' => encrypt('pw')]);
        $creator = $this->makeUser();

        $this->withSession(['username' => 'tester', 'userpassword' => 'pw', 'user_type' => 0])
            ->post('/adjustUserEarnings_FromAdmin', ['user_id' => $creator->id, 'amount' => '500', 'reason' => 'x'])
            ->assertStatus(403);
        $this->withSession(['username' => 'tester', 'userpassword' => 'pw', 'user_type' => 0])
            ->post('/addCoinsToUserWallet_FromAdmin', ['user_id' => $creator->id, 'coins' => 5000, 'reason' => 'x'])
            ->assertStatus(403);

        $this->assertSame('0.0000', $this->earnings($creator->id));
        $this->assertSame(0, (int) $this->userRow($creator->id)->coin_wallet);
    }
}
