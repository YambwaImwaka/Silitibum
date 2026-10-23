<?php

namespace App\Services\Payments;

use App\Models\CoinTopUpRequests;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Services\Verification\BlueTick;
use App\Services\Wallet\Money;
use App\Services\Wallet\WalletLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The single place a web top-up is allowed to move from non-final to final and
// credit the wallet. Both entry points go through here — the provider webhook
// (PaymentWebhookController) and the sweep that catches the webhooks which
// never arrive (ReconcileTopUps) — precisely so the two can never drift into
// two subtly different definitions of "already credited".
class TopUpReconciler
{
    /// References a top-up may be known by at the provider, best first.
    ///
    /// Our own 'topup_{id}' leads deliberately: it is what we sent on the
    /// charge and it is the one that actually resolves. Top-up 1's stored
    /// provider_reference ('2620809422') came back "Collection details was not
    /// found" from Lenco while 'topup_1' came back successful, so trying only
    /// the stored reference would have concluded the payment failed.
    public static function referencesFor(CoinTopUpRequests $topup): array
    {
        return array_values(array_unique(array_filter([
            'topup_' . $topup->id,
            $topup->provider_reference,
        ])));
    }

    /// Applies a verified provider status to a top-up.
    ///
    /// Returns true only when THIS call is the one that credited the wallet.
    ///
    /// The claim is the write, never a preceding read: providers fan the same
    /// event out concurrently (Lenco delivered one three times inside a single
    /// second), so a `if (status === completed) return;` guard lets every
    /// delivery through before any of them has written. One conditional UPDATE
    /// decides the winner, and only the winner credits.
    ///
    /// A provider-confirmed payment also rescues a row previously marked
    /// 'failed'. Until GatewayResult existed, a status check that merely timed
    /// out was recorded as a failure, so 'failed' rows can hide real payments;
    /// the provider saying the money arrived outranks our earlier guess. Only
    /// 'completed' is final in that direction.
    public static function apply(CoinTopUpRequests $topup, string $verifiedStatus): bool
    {
        if ($verifiedStatus === 'completed') {
            $isBlueTick = ($topup->purpose ?? 'coins') === 'verification';
            $tickUntil = false;
            // Claim and credit commit together: a crash between them can
            // neither leave a 'completed' top-up without its coins nor let a
            // retry credit twice (the ledger key topup:{id} is the backstop).
            $credited = DB::transaction(function () use ($topup, $isBlueTick, &$tickUntil) {
                $claimed = CoinTopUpRequests::where('id', $topup->id)
                    ->where('status', '!=', 'completed')
                    ->update(['status' => 'completed', 'updated_at' => now()]);
                if ($claimed !== 1) {
                    return false; // a concurrent delivery already credited it
                }
                if ($topup->user_id === null) {
                    return false; // the buyer deleted their account meanwhile
                }
                if ($isBlueTick) {
                    // A paid blue tick: one more period, from the end of the
                    // current one (BlueTick). The claim above makes it once.
                    $days = (int) (GlobalSettings::first()->verification_days ?? 30);
                    $tickUntil = BlueTick::extendPaid((int) $topup->user_id, $days);
                    return true;
                }
                // The coins carry what was paid for them; that is what a gift
                // of them is worth to the creator who receives it.
                return WalletLedger::creditCoins(
                    (int) $topup->user_id,
                    (int) $topup->coins,
                    Money::toUnits((string) $topup->amount),
                    WalletLedger::TYPE_PURCHASE_TOPUP,
                    'topup:' . $topup->id,
                    ['topup_id' => $topup->id]
                );
            });

            if ($credited && $isBlueTick) {
                Log::info("TopUpReconciler: blue tick paid by user {$topup->user_id} (top-up {$topup->id})");
                $body = $tickUntil instanceof Carbon
                    ? 'Your blue tick is active until ' . $tickUntil->format('j M Y') . '.'
                    : 'Your blue tick is now active.';
                GlobalFunction::pushToUser((int) $topup->user_id, 'You are verified!', $body,
                    ['type' => 'verification']);
            } elseif ($credited) {
                Log::info("TopUpReconciler: credited {$topup->coins} coins to user {$topup->user_id} for top-up {$topup->id}");
            }
            return $credited;
        }

        if ($verifiedStatus === 'failed') {
            CoinTopUpRequests::where('id', $topup->id)
                ->whereNotIn('status', ['completed', 'failed'])
                ->update(['status' => 'failed', 'updated_at' => now()]);
        }

        // 'processing' — leave non-final so a later webhook or sweep resolves it.
        return false;
    }
}
