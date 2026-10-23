<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\WalletController;
use App\Models\Constants;
use App\Models\GlobalFunction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

class WithdrawalHardeningTest extends ApiTestCase
{
    private function redeem(object $user, int $status, string $provider = 'manual', int $coins = 300): int
    {
        return DB::table('tbl_redeem_request')->insertGetId([
            'user_id' => $user->id,
            'request_number' => GlobalFunction::generateRedeemRequestNumber($user->id),
            'gateway' => 'Airtel Money',
            'account' => '0971234567',
            'provider' => $provider,
            'amount' => '15',
            'coins' => $coins,
            'coin_value' => 0.05,
            'status' => $status,
            'created_at' => now(),
        ]);
    }

    public function test_request_numbers_are_unique_and_provider_safe(): void
    {
        $numbers = [];
        for ($i = 0; $i < 200; $i++) {
            $numbers[] = GlobalFunction::generateRedeemRequestNumber(42);
        }
        $this->assertCount(200, array_unique($numbers));
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^WD42-[A-Z0-9]{8}$/', $number);
        }
    }

    public function test_manual_processing_rows_get_mark_paid_and_reject(): void
    {
        $user = $this->makeUser();
        $this->redeem($user, Constants::withdrawalProcessing, 'manual');
        $this->redeem($user, Constants::withdrawalProcessing, 'lenco');

        $json = app(WalletController::class)
            ->listProcessingWithdrawals(new Request(['start' => 0, 'length' => 10, 'draw' => 1]))
            ->getData(true);

        $actions = array_column($json['data'], 5);
        $this->assertCount(2, $actions);
        // newest first: the lenco row, then the manual one
        $this->assertStringContainsString('recheck', $actions[0]);
        $this->assertStringNotContainsString('complete', $actions[0]);
        $this->assertStringContainsString('complete', $actions[1]);
        $this->assertStringContainsString('reject', $actions[1]);
    }

    public function test_manual_processing_payout_can_be_rejected_once(): void
    {
        $user = $this->makeUser(['coin_wallet' => 0]);
        $id = $this->redeem($user, Constants::withdrawalProcessing, 'manual', 300);

        $first = app(WalletController::class)->rejectWithdrawal(new Request(['id' => $id]));
        $second = app(WalletController::class)->rejectWithdrawal(new Request(['id' => $id]));

        $this->assertTrue($first->getData(true)['status']);
        $this->assertFalse($second->getData(true)['status']);
        // Refunds go to earnings, at the request's recorded amount (K15),
        // exactly once — never back into spendable coins.
        $this->assertSame('15.0000', $this->earnings($user->id));
        $this->assertSame(0, (int) $this->userRow($user->id)->coin_wallet);
    }

    public function test_automated_processing_payout_cannot_be_refunded_by_hand(): void
    {
        $user = $this->makeUser(['coin_wallet' => 0]);
        $id = $this->redeem($user, Constants::withdrawalProcessing, 'lenco', 300);

        $response = app(WalletController::class)->rejectWithdrawal(new Request(['id' => $id]));

        $this->assertFalse($response->getData(true)['status']);
        $this->assertSame(0, (int) $this->userRow($user->id)->coin_wallet);
        $this->assertSame(Constants::withdrawalProcessing, (int) DB::table('tbl_redeem_request')->where('id', $id)->value('status'));
    }

    public function test_account_deletion_waits_for_an_open_withdrawal(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);
        $this->redeem($user, Constants::withdrawalPending);

        $this->api('user/deleteMyAccount', [], $token)->assertJson(['status' => false]);

        $this->assertNotNull($this->userRow($user->id));
    }

    public function test_account_deletion_keeps_payout_history_without_the_person(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);
        $id = $this->redeem($user, Constants::withdrawalCompleted);

        $this->api('user/deleteMyAccount', [], $token)->assertJson(['status' => true]);

        $this->assertNull($this->userRow($user->id));
        $row = DB::table('tbl_redeem_request')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
    }
}
