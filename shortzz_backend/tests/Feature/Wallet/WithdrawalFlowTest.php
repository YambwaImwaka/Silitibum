<?php

namespace Tests\Feature\Wallet;

use App\Http\Controllers\WalletController;
use App\Models\Constants;
use App\Services\Wallet\Money;
use App\Services\Wallet\WalletLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestCase;

class WithdrawalFlowTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting([
            'is_withdrawal_on' => 1,
            'min_withdrawal_amount' => 50,
            'earnings_hold_days' => 7,
            'max_withdrawals_per_week' => 1,
            'creator_min_account_age_days' => 30,
            'payout_change_hold_hours' => 72,
            'active_payout_provider' => 'manual',
            'lenco_api_key' => null,
        ]);
    }

    /// A creator whose earnings cleared the hold window long ago.
    private function creatorWithCleared(string $amount, array $userAttributes = []): object
    {
        $creator = $this->makeUser($userAttributes);
        WalletLedger::creditEarnings($creator->id, Money::toUnits($amount), WalletLedger::TYPE_GIFT_RECEIVED, null);
        DB::table('wallet_transactions')->where('user_id', $creator->id)->update(['created_at' => now()->subDays(10)]);
        return $creator;
    }

    private function withdraw(object $user, string $amount)
    {
        return $this->api('misc/submitWithdrawalRequest', ['amount' => $amount], $this->tokenFor($user));
    }

    public function test_old_app_coin_withdrawals_are_asked_to_update(): void
    {
        $user = $this->makeUser(['coin_wallet' => 5000]);

        $this->api('misc/submitWithdrawalRequest', ['coins' => 1000, 'gateway' => 'Mobile Money', 'account' => '0971234567'], $this->tokenFor($user))
            ->assertJson(['status' => false, 'message' => 'Please update Silitibum to withdraw your earnings.']);
    }

    public function test_paused_withdrawals_are_refused(): void
    {
        $this->setting(['is_withdrawal_on' => 0]);
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);

        $this->withdraw($creator, '60')->assertJson(['status' => false, 'message' => 'withdrawals are currently disabled']);
    }

    public function test_withdrawal_needs_a_payout_account(): void
    {
        $creator = $this->creatorWithCleared('100.00');

        $response = $this->withdraw($creator, '60')->assertJson(['status' => false]);

        $this->assertStringContainsString('Mobile money account added', $response->json('message'));
        $this->assertSame('100.0000', $this->earnings($creator->id));
    }

    public function test_new_accounts_must_wait(): void
    {
        $creator = $this->creatorWithCleared('100.00', ['created_at' => now()->subDays(3)]);
        $this->payoutAccount($creator);

        $this->assertStringContainsString('days old', $this->withdraw($creator, '60')->json('message'));
    }

    public function test_a_known_name_mismatch_blocks_until_an_admin_accepts_it(): void
    {
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator, ['name_match' => 'mismatch']);

        $this->withdraw($creator, '60')->assertJson(['status' => false]);

        DB::table('creator_profiles')->where('user_id', $creator->id)->update(['name_override_by' => 'admin']);
        $this->withdraw($creator, '60')->assertJson(['status' => true]);
    }

    public function test_successful_request_debits_earnings_and_waits_for_an_admin(): void
    {
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);

        $this->withdraw($creator, '60')->assertJson(['status' => true]);

        $this->assertSame('40.0000', $this->earnings($creator->id));
        $request = DB::table('tbl_redeem_request')->where('user_id', $creator->id)->first();
        $this->assertSame(Constants::withdrawalPending, (int) $request->status);
        $this->assertSame('60.00', $request->amount);
        $this->assertSame('0971234567', $request->account);
        $this->assertSame('airtel', $request->operator);
        $this->assertSame(0, (int) $request->attempt, 'nothing is sent to a provider at submit');
    }

    public function test_minimum_and_balance_are_enforced(): void
    {
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);

        $this->assertStringContainsString('minimum', $this->withdraw($creator, '49.99')->json('message'));
        $this->withdraw($creator, '100.01')->assertJson(['status' => false]);
        $this->assertSame('100.0000', $this->earnings($creator->id));
    }

    public function test_recent_gift_earnings_are_on_hold(): void
    {
        $creator = $this->creatorWithCleared('30.00');
        $this->payoutAccount($creator);
        WalletLedger::creditEarnings($creator->id, Money::toUnits('70.00'), WalletLedger::TYPE_GIFT_RECEIVED, null);

        $summary = WalletLedger::summary($creator->id);
        $this->assertSame('100.00', $summary['earnings_balance']);
        $this->assertSame('30.00', $summary['earnings_available']);
        $this->assertSame('70.00', $summary['earnings_on_hold']);

        $this->withdraw($creator, '60')->assertJson(['status' => false]);
    }

    public function test_one_open_request_and_weekly_cap(): void
    {
        $creator = $this->creatorWithCleared('200.00');
        $this->payoutAccount($creator);

        $this->withdraw($creator, '50')->assertJson(['status' => true]);
        $this->assertStringContainsString('in progress', $this->withdraw($creator, '50')->json('message'));

        $id = DB::table('tbl_redeem_request')->where('user_id', $creator->id)->value('id');
        app(WalletController::class)->completeWithdrawal(new Request(['id' => $id]));
        $this->assertStringContainsString('per week', $this->withdraw($creator, '50')->json('message'));
    }

    public function test_reject_returns_the_money_exactly_once(): void
    {
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);
        $this->withdraw($creator, '60')->assertJson(['status' => true]);
        $id = DB::table('tbl_redeem_request')->where('user_id', $creator->id)->value('id');

        $first = app(WalletController::class)->rejectWithdrawal(new Request(['id' => $id, 'reason' => 'Number unreachable']));
        $second = app(WalletController::class)->rejectWithdrawal(new Request(['id' => $id, 'reason' => 'again']));

        $this->assertTrue($first->getData(true)['status']);
        $this->assertFalse($second->getData(true)['status']);
        $this->assertSame('100.0000', $this->earnings($creator->id));
        $this->assertSame('Number unreachable', DB::table('tbl_redeem_request')->where('id', $id)->value('reason'));
    }

    public function test_automated_approve_sends_through_lenco_and_failure_refunds(): void
    {
        $this->setting(['active_payout_provider' => 'lenco', 'lenco_api_key' => 'test-key', 'lenco_account_id' => '9a1b2c3d-0000-4000-8000-000000000001']);
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);
        $this->withdraw($creator, '60')->assertJson(['status' => true]);
        $redeem = DB::table('tbl_redeem_request')->where('user_id', $creator->id)->first();

        // One stateful fake: Laravel uses the FIRST matching stub, so a second
        // Http::fake() for the same URL would never be reached.
        $attempt = 0;
        Http::fake(function ($request) use (&$attempt) {
            if (!str_ends_with($request->url(), '/transfers/mobile-money')) {
                return Http::response([], 404);
            }
            $attempt++;
            return $attempt === 1
                ? Http::response(['status' => true, 'data' => ['status' => 'failed', 'reasonForFailure' => 'Recipient wallet limit exceeded', 'fee' => '1.50', 'lencoReference' => '240010002']], 200)
                : Http::response(['status' => true, 'data' => ['status' => 'successful', 'lencoReference' => '240010003']], 200);
        });
        app(WalletController::class)->approveWithdrawal(new Request(['id' => $redeem->id]));

        Http::assertSent(function ($request) use ($redeem) {
            return str_ends_with($request->url(), '/transfers/mobile-money')
                && $request['reference'] === $redeem->request_number . '-1'
                && $request['phone'] === '0971234567'
                && $request['operator'] === 'airtel'
                && $request['country'] === 'zm'
                && $request['accountId'] === '9a1b2c3d-0000-4000-8000-000000000001';
        });
        $row = DB::table('tbl_redeem_request')->where('id', $redeem->id)->first();
        $this->assertSame(Constants::withdrawalFailed, (int) $row->status);
        $this->assertSame('Recipient wallet limit exceeded', $row->reason);
        $this->assertSame('1.50', $row->fee);
        $this->assertSame('100.0000', $this->earnings($creator->id));

        // Retry takes the money out again and sends a NEW reference.
        app(WalletController::class)->retryWithdrawal(new Request(['id' => $redeem->id]));

        $row = DB::table('tbl_redeem_request')->where('id', $redeem->id)->first();
        $this->assertSame(Constants::withdrawalCompleted, (int) $row->status);
        $this->assertSame($redeem->request_number . '-2', $row->provider_reference);
        $this->assertSame('40.0000', $this->earnings($creator->id));
    }

    public function test_provider_timeout_never_refunds_and_the_sweep_settles_it(): void
    {
        $this->setting(['active_payout_provider' => 'lenco', 'lenco_api_key' => 'test-key', 'lenco_account_id' => 'acc']);
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);
        $this->withdraw($creator, '60')->assertJson(['status' => true]);
        $redeem = DB::table('tbl_redeem_request')->where('user_id', $creator->id)->first();

        $lencoDown = true;
        Http::fake(function ($request) use (&$lencoDown) {
            if ($lencoDown) {
                throw new \Illuminate\Http\Client\ConnectionException('timed out');
            }
            return str_contains($request->url(), '/transfers/status/')
                ? Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)
                : Http::response([], 404);
        });
        app(WalletController::class)->approveWithdrawal(new Request(['id' => $redeem->id]));
        $this->assertSame(Constants::withdrawalProcessing, (int) DB::table('tbl_redeem_request')->where('id', $redeem->id)->value('status'));
        $this->assertSame('40.0000', $this->earnings($creator->id));

        DB::table('tbl_redeem_request')->where('id', $redeem->id)->update(['updated_at' => now()->subMinutes(10)]);
        $lencoDown = false;
        $this->artisan('payouts:reconcile')->assertExitCode(0);

        $this->assertSame(Constants::withdrawalCompleted, (int) DB::table('tbl_redeem_request')->where('id', $redeem->id)->value('status'));
        $this->assertSame('40.0000', $this->earnings($creator->id));
    }

    public function test_payout_account_name_check_never_reveals_the_name(): void
    {
        $this->setting(['lenco_api_key' => 'test-key']);
        $creator = $this->makeUser();
        Http::fake(['*/resolve/mobile-money' => Http::response(['status' => true, 'data' => ['accountName' => 'BEATA JEAN', 'phone' => '0971234567', 'operator' => 'airtel']], 200)]);

        $response = $this->api('misc/savePayoutAccount', [
            'legal_name' => 'Jean Beata Mwansa', 'phone' => '+260971234567', 'operator' => 'airtel', 'confirm_adult' => 1,
        ], $this->tokenFor($creator))->assertJson(['status' => true]);

        $this->assertSame('match', $response->json('data.payout_account.name_match'));
        $this->assertStringNotContainsString('BEATA JEAN', $response->getContent());
        $this->assertSame('BEATA JEAN', DB::table('creator_profiles')->where('user_id', $creator->id)->value('payout_account_name'));

        $this->api('misc/savePayoutAccount', [
            'legal_name' => 'Jean Beata Mwansa', 'phone' => '0961234567', 'operator' => 'airtel', 'confirm_adult' => 1,
        ], $this->tokenFor($creator))->assertJson(['status' => false]);
    }

    public function test_changing_the_payout_number_starts_a_hold(): void
    {
        $this->setting(['lenco_api_key' => null]);
        $creator = $this->creatorWithCleared('100.00');
        $this->payoutAccount($creator);

        $this->api('misc/savePayoutAccount', [
            'legal_name' => 'Beata Jean Mwansa', 'phone' => '0961234567', 'operator' => 'mtn', 'confirm_adult' => 1,
        ], $this->tokenFor($creator))->assertJson(['status' => true]);

        $this->assertStringContainsString('not changed', $this->withdraw($creator, '60')->json('message'));
    }
}
