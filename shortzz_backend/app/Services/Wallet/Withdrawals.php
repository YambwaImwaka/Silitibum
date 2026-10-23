<?php

namespace App\Services\Wallet;

use App\Models\Constants;
use App\Models\GlobalFunction;
use App\Models\RedeemRequests;
use Illuminate\Support\Facades\DB;

// Every state change a withdrawal can go through after it is requested, in one
// place: admin buttons (WalletController), provider webhooks
// (PaymentWebhookController) and status re-checks all land here.
//
// Each transition is claimed by a conditional UPDATE on the status — never a
// status read first — so a double-clicked button, a webhook racing a recheck
// or three copies of the same delivery can only ever move a request once, and
// only the call that moved it refunds or notifies.
final class Withdrawals
{
    public const OPEN = [Constants::withdrawalPending, Constants::withdrawalProcessing];

    /// Paid by a human out of band (ManualGateway), or a request from before
    /// automated providers existed.
    public static function isManual(RedeemRequests $redeem): bool
    {
        return $redeem->provider === null || $redeem->provider === '' || $redeem->provider === 'manual';
    }

    /// What an admin may close by hand: a request still Pending, or a
    /// Processing payout a human is settling (manual provider). An automated
    /// payout in flight is closed only by its provider's verdict. This lives
    /// in the claim itself, not in a status read beforehand: Reject used to
    /// read a Pending row, Approve then sent it to Lenco, and Reject's update
    /// still matched — the creator was paid AND refunded.
    private static function closableByAdmin(int $id)
    {
        return RedeemRequests::where('id', $id)->where(function ($q) {
            $q->where('status', Constants::withdrawalPending)
                ->orWhere(function ($q) {
                    $q->where('status', Constants::withdrawalProcessing)
                        ->where(function ($q) {
                            $q->whereNull('provider')->orWhereIn('provider', ['', 'manual']);
                        });
                });
        });
    }

    /// A provider verdict is about ONE attempt: it may only close the request
    /// while that attempt is still the current one. Without this, a sweep that
    /// asked about attempt 1 could close attempt 2 (sent by a retry while it
    /// waited), whose refund key then no longer matched — K60 debited, never
    /// paid, never refunded.
    private static function currentAttempt(RedeemRequests $redeem)
    {
        return RedeemRequests::where('id', $redeem->id)
            ->where('status', Constants::withdrawalProcessing)
            ->where('attempt', (int) $redeem->attempt);
    }

    /// Admin confirms the money reached the creator.
    public static function complete(RedeemRequests $redeem, ?string $by = null): bool
    {
        $claimed = self::closableByAdmin((int) $redeem->id)
            ->update(['status' => Constants::withdrawalCompleted, 'processed_by' => $by, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        self::notify($redeem, 'Withdrawal sent', 'K' . self::amount($redeem) . ' is on its way to your mobile money account.');
        return true;
    }

    /// Admin declines a request; the money goes back to the creator's earnings.
    public static function reject(RedeemRequests $redeem, ?string $reason, ?string $by = null): bool
    {
        // Claim and refund commit together: a request can never end up
        // rejected with the creator's money still out of their earnings.
        $claimed = DB::transaction(function () use ($redeem, $reason, $by) {
            $claimed = self::closableByAdmin((int) $redeem->id)
                ->update([
                    'status' => Constants::withdrawalRejected,
                    'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                    'processed_by' => $by,
                    'updated_at' => now(),
                ]);
            if ($claimed === 1) {
                self::refund($redeem, 'rejected');
            }
            return $claimed === 1;
        });
        if (!$claimed) {
            return false;
        }
        self::notify($redeem, 'Withdrawal declined', ($reason ?: 'Your withdrawal was declined.') . ' K' . self::amount($redeem) . ' is back in your earnings.');
        return true;
    }

    /// The provider confirmed the transfer failed.
    public static function fail(RedeemRequests $redeem, ?string $reason = null): bool
    {
        $claimed = DB::transaction(function () use ($redeem, $reason) {
            $claimed = self::currentAttempt($redeem)
                ->update(['status' => Constants::withdrawalFailed, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'updated_at' => now()]);
            if ($claimed === 1) {
                self::refund($redeem, 'failed');
            }
            return $claimed === 1;
        });
        if (!$claimed) {
            return false;
        }
        self::notify($redeem, 'Withdrawal failed', 'We could not send K' . self::amount($redeem) . '. It is back in your earnings.');
        return true;
    }

    /// The provider confirmed the transfer arrived.
    public static function providerCompleted(RedeemRequests $redeem): bool
    {
        $claimed = self::currentAttempt($redeem)
            ->update(['status' => Constants::withdrawalCompleted, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        self::notify($redeem, 'Withdrawal sent', 'K' . self::amount($redeem) . ' has been sent to your mobile money account.');
        return true;
    }

    /// Applies a verified provider status (GatewayResult vocabulary).
    public static function applyProviderStatus(RedeemRequests $redeem, array $result): void
    {
        if ($result['status'] === 'completed') {
            self::providerCompleted($redeem);
        } elseif ($result['status'] === 'failed') {
            self::fail($redeem, $result['message'] ?? null);
        }
        // 'processing' — a later webhook, recheck or sweep resolves it.
    }

    /// Puts the request's amount back into earnings. Keyed per attempt, so a
    /// request that fails, is retried and fails again refunds twice — once
    /// for each time it was debited — and never more.
    public static function refund(RedeemRequests $redeem, string $why): void
    {
        if ($redeem->user_id === null) {
            return; // the account was deleted; nothing to refund into
        }
        $units = Money::toUnits((string) $redeem->amount);
        if ($units <= 0) {
            return;
        }
        WalletLedger::creditEarnings(
            (int) $redeem->user_id,
            $units,
            WalletLedger::TYPE_WITHDRAWAL_REFUND,
            'wd-refund:' . $redeem->id . ':' . (int) $redeem->attempt,
            ['redeem_request_id' => $redeem->id],
            ['why' => $why]
        );
    }

    private static function amount(RedeemRequests $redeem): string
    {
        return Money::format(Money::toUnits((string) $redeem->amount));
    }

    private static function notify(RedeemRequests $redeem, string $title, string $body): void
    {
        if ($redeem->user_id === null) {
            return;
        }
        GlobalFunction::pushToUser((int) $redeem->user_id, $title, $body, ['type' => 'withdrawal', 'id' => (string) $redeem->id]);
    }
}
