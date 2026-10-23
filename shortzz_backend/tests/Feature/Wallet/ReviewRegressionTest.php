<?php

namespace Tests\Feature\Wallet;

use App\Http\Controllers\WalletController;
use App\Models\CoinTopUpRequests;
use App\Models\Constants;
use App\Models\RedeemRequests;
use App\Services\Payments\RevenueCatCoinSync;
use App\Services\Payments\TopUpReconciler;
use App\Services\Wallet\Money;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\Withdrawals;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestCase;

// Scenarios found by the independent review of the wallet code. Each one lost
// or minted money before its fix; these keep them fixed.
class ReviewRegressionTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting([
            'is_withdrawal_on' => 1, 'min_withdrawal_amount' => 50, 'earnings_hold_days' => 7,
            'max_withdrawals_per_week' => 1, 'creator_min_account_age_days' => 30, 'payout_change_hold_hours' => 72,
            'active_payout_provider' => 'lenco', 'lenco_api_key' => 'test-key', 'lenco_account_id' => 'acc-1',
            'creator_share_percent' => 60, 'coin_value' => 0.05, 'registration_bonus_status' => 0,
        ]);
    }

    private function pendingWithdrawal(): array
    {
        $creator = $this->makeUser();
        WalletLedger::creditEarnings($creator->id, Money::toUnits('100.00'), WalletLedger::TYPE_GIFT_RECEIVED, null);
        DB::table('wallet_transactions')->where('user_id', $creator->id)->update(['created_at' => now()->subDays(10)]);
        $this->payoutAccount($creator);
        $this->api('misc/submitWithdrawalRequest', ['amount' => '60'], $this->tokenFor($creator))->assertJson(['status' => true]);
        return [$creator, (int) DB::table('tbl_redeem_request')->where('user_id', $creator->id)->value('id')];
    }

    private function status(int $id): int
    {
        return (int) DB::table('tbl_redeem_request')->where('id', $id)->value('status');
    }

    public function test_a_stale_verdict_about_attempt_one_cannot_close_attempt_two(): void
    {
        [$creator, $id] = $this->pendingWithdrawal();
        $phase = 'approve';
        Http::fake(function ($request) use (&$phase, $id) {
            $url = $request->url();
            if (str_ends_with($url, '/transfers/mobile-money')) {
                return Http::response(['status' => true, 'data' => ['status' => 'pending']], 200);
            }
            if (str_contains($url, '/transfers/status/') && $phase === 'attempt2-failed') {
                return Http::response(['status' => true, 'data' => ['status' => 'failed']], 200);
            }
            if (str_contains($url, '/transfers/status/') && $phase === 'sweep') {
                $phase = 'done';
                // While the sweep waits on Lenco about attempt 1: attempt 1's
                // failure arrives (refund) and an admin retries (attempt 2).
                Withdrawals::fail(RedeemRequests::find($id), 'Recipient wallet limit exceeded');
                app(WalletController::class)->retryWithdrawal(new Request(['id' => $id]));
                return Http::response(['status' => true, 'data' => ['status' => 'failed']], 200);
            }
            return Http::response(['status' => true, 'data' => ['status' => 'pending']], 200);
        });

        app(WalletController::class)->approveWithdrawal(new Request(['id' => $id]));
        DB::table('tbl_redeem_request')->where('id', $id)->update(['updated_at' => now()->subMinutes(10)]);
        $phase = 'sweep';
        $this->artisan('payouts:reconcile')->assertExitCode(0);

        $row = DB::table('tbl_redeem_request')->where('id', $id)->first();
        $this->assertSame(2, (int) $row->attempt);
        $this->assertSame(Constants::withdrawalProcessing, (int) $row->status, 'attempt 2 is still in flight');
        $this->assertSame('40.0000', $this->earnings($creator->id), 'attempt 1 refunded, attempt 2 debited');

        // Attempt 2's own failure is still refunded.
        $phase = 'attempt2-failed';
        app(WalletController::class)->recheckWithdrawal(new Request(['id' => $id]));
        $this->assertSame(Constants::withdrawalFailed, $this->status($id));
        $this->assertSame('100.0000', $this->earnings($creator->id));
        $this->assertSame(0, Artisan::call('wallet:reconcile'));
    }

    public function test_reject_cannot_refund_a_payout_approve_already_sent(): void
    {
        [$creator, $id] = $this->pendingWithdrawal();
        $staleForReject = RedeemRequests::find($id); // loaded while Pending
        $rejected = null;
        Http::fake(function ($request) use ($staleForReject, &$rejected) {
            if (str_ends_with($request->url(), '/transfers/mobile-money')) {
                $rejected = Withdrawals::reject($staleForReject, 'double click', 'admin2');
                return Http::response(['status' => true, 'data' => ['status' => 'successful']], 200);
            }
            return Http::response([], 404);
        });

        app(WalletController::class)->approveWithdrawal(new Request(['id' => $id]));

        $this->assertFalse($rejected);
        $this->assertSame(Constants::withdrawalCompleted, $this->status($id));
        $this->assertSame('40.0000', $this->earnings($creator->id), 'paid once, never refunded');
    }

    public function test_a_payout_that_never_reached_the_provider_is_refunded_after_a_day(): void
    {
        [$creator, $id] = $this->pendingWithdrawal();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/transfers/mobile-money')) {
                throw new ConnectionException('cURL error 6: Could not resolve host: api.lenco.co');
            }
            return Http::response(['status' => false, 'message' => 'Transfer not found'], 404);
        });
        app(WalletController::class)->approveWithdrawal(new Request(['id' => $id]));

        // Within a day "not found" may just be slow; it stays open.
        DB::table('tbl_redeem_request')->where('id', $id)->update(['updated_at' => now()->subHours(2)]);
        $this->artisan('payouts:reconcile')->assertExitCode(0);
        $this->assertSame(Constants::withdrawalProcessing, $this->status($id));

        DB::table('tbl_redeem_request')->where('id', $id)->update(['updated_at' => now()->subDays(2)]);
        $this->artisan('payouts:reconcile')->assertExitCode(0);
        $this->assertSame(Constants::withdrawalFailed, $this->status($id));
        $this->assertSame('100.0000', $this->earnings($creator->id));
    }

    private array $purchases = [];
    private bool $faked = false;

    private function revenueCatHas(array $purchases): void
    {
        foreach (['RC_PROJECT_ID' => 'projtest', 'RC_KIT_API_KEY' => 'sk_test'] as $key => $value) {
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
        config(['services.revenuecat.allow_sandbox' => false]);
        $this->purchases = $purchases;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        if (!DB::table('tbl_coin_plan')->where('playstore_product_id', 'coins_500_rr')->exists()) {
            DB::table('tbl_coin_plan')->insert(['coin_amount' => 500, 'coin_plan_price' => 5, 'playstore_product_id' => 'coins_500_rr', 'appstore_product_id' => '', 'status' => 1, 'image' => 'x.png']);
        }
        cache()->flush();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/purchases')) {
                return Http::response(['items' => $this->purchases, 'next_page' => null], 200);
            }
            if (str_contains($request->url(), '/products/prodrr500')) {
                return Http::response(['store_identifier' => 'coins_500_rr'], 200);
            }
            return Http::response([], 404);
        });
    }

    private function purchase(string $orderId, string $status = 'owned'): array
    {
        return ['id' => 'p_' . $orderId, 'product_id' => 'prodrr500', 'store' => 'play_store', 'store_purchase_identifier' => $orderId,
            'environment' => 'production', 'status' => $status, 'quantity' => 1];
    }

    protected function tearDown(): void
    {
        foreach (['RC_PROJECT_ID', 'RC_KIT_API_KEY'] as $key) {
            unset($_SERVER[$key]);
            putenv($key);
        }
        parent::tearDown();
    }

    public function test_refund_of_gifted_coins_reverses_the_creator_and_platform_share(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        $this->revenueCatHas([$this->purchase('GPA.RR-1')]);
        RevenueCatCoinSync::syncCustomer($fan->id);
        $this->api('misc/sendGift', ['user_id' => $creator->id, 'gift_id' => $this->makeGift(500)->id], $this->tokenFor($fan))->assertJson(['status' => true]);
        $this->assertSame('3.0000', $this->earnings($creator->id));

        $this->revenueCatHas([$this->purchase('GPA.RR-1', 'refunded')]);
        RevenueCatCoinSync::syncCustomer($fan->id);
        RevenueCatCoinSync::syncCustomer($fan->id);

        $this->assertSame('0.0000', $this->earnings($creator->id));
        $platform = Money::toUnits((string) DB::table('wallet_transactions')->where('account', 'platform')->sum('amount'));
        $this->assertSame(0, $platform, 'the K2 commission on refunded money is reversed too');
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'gift_reversed')->count());
        $this->assertSame(0, Artisan::call('wallet:reconcile'));
    }

    public function test_refund_removes_the_price_paid_not_the_average_cost(): void
    {
        $fan = $this->makeUser();
        $creator = $this->makeUser();
        WalletLedger::creditCoins($fan->id, 10000, 0, WalletLedger::TYPE_ADMIN_ADJUSTMENT, null, [], ['reason' => 'promo']);
        $this->revenueCatHas([$this->purchase('GPA.RR-2')]);
        RevenueCatCoinSync::syncCustomer($fan->id);

        $this->revenueCatHas([$this->purchase('GPA.RR-2', 'refunded')]);
        RevenueCatCoinSync::syncCustomer($fan->id);

        $row = $this->userRow($fan->id);
        $this->assertSame(10000, (int) $row->coin_wallet);
        $this->assertSame('0.0000', $row->coin_wallet_value, 'the refunded K5 no longer rides on free coins');
        $this->api('misc/sendGift', ['user_id' => $creator->id, 'gift_id' => $this->makeGift(10000)->id], $this->tokenFor($fan))->assertJson(['status' => true]);
        $this->assertSame('0.0000', $this->earnings($creator->id));
    }

    public function test_a_gift_in_the_deploy_window_converts_the_legacy_wallet_first(): void
    {
        // Wallets exactly as the old code left them: no ledger rows.
        $fan = $this->makeUser(['coin_wallet' => 500, 'coin_purchased_lifetime' => 500]);
        DB::table('tbl_coin_topup_request')->insert(['user_id' => $fan->id, 'coin_package_id' => 9, 'coins' => 500, 'amount' => 5.00,
            'phone' => '0971234567', 'provider' => 'lenco', 'status' => 'completed', 'created_at' => now()->subDays(20), 'updated_at' => now()->subDays(20)]);
        $creator = $this->makeUser(['coin_wallet' => 100, 'coin_collected_lifetime' => 100]);

        // New code is live; wallet:migrate-earnings has not run yet.
        $this->api('misc/sendGift', ['user_id' => $creator->id, 'gift_id' => $this->makeGift(5)->id], $this->tokenFor($fan))->assertJson(['status' => true]);

        $this->assertSame('4.9500', $this->userRow($fan->id)->coin_wallet_value);
        // 100 legacy received coins at 60% of what buyers paid (K5 for 500:
        // K0.006 each) + 60% of the K0.05 the gift's coins cost.
        $this->assertSame('0.6300', $this->earnings($creator->id));
        $this->assertSame(0, (int) $this->userRow($creator->id)->coin_wallet);

        $this->assertSame(0, Artisan::call('wallet:migrate-earnings'));
        $this->assertSame('0.6300', $this->earnings($creator->id), 'the bulk run skips converted wallets');
        $this->assertSame(0, Artisan::call('wallet:reconcile'));
    }

    public function test_a_top_up_in_the_deploy_window_keeps_the_creators_honoured_coins(): void
    {
        $creator = $this->makeUser(['coin_wallet' => 100, 'coin_collected_lifetime' => 100]);
        $id = DB::table('tbl_coin_topup_request')->insertGetId(['user_id' => $creator->id, 'coin_package_id' => 9, 'coins' => 500, 'amount' => 5.00,
            'phone' => '0971234567', 'provider' => 'lenco', 'status' => 'processing', 'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10)]);

        $this->assertTrue(TopUpReconciler::apply(CoinTopUpRequests::find($id), 'completed'));

        $row = $this->userRow($creator->id);
        $this->assertSame(500, (int) $row->coin_wallet);
        $this->assertSame('5.0000', $row->coin_wallet_value);
        $this->assertSame('0.6000', $this->earnings($creator->id), '100 legacy received coins at K0.006');
        $this->assertSame(0, Artisan::call('wallet:reconcile'));
    }

    public function test_reconcile_does_not_alarm_on_wallets_not_yet_converted(): void
    {
        $this->makeUser(['coin_wallet' => 300]);

        $this->assertSame(0, Artisan::call('wallet:reconcile'));
        $this->assertStringContainsString('not converted yet', Artisan::output());
    }
}
