<?php

namespace App\Services\Payments;

use App\Models\CoinTopUpRequests;
use App\Models\Users;
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
    public static function apply(CoinTopUpRequests $topup, string $verifiedStatus): bool
    {
        if ($verifiedStatus === 'completed') {
            $claimed = CoinTopUpRequests::where('id', $topup->id)
                ->whereNotIn('status', ['completed', 'failed'])
                ->update(['status' => 'completed', 'updated_at' => now()]);

            if ($claimed !== 1) {
                return false; // a concurrent delivery already credited it
            }

            // incrementEach() is a single `SET col = col + n` statement, so
            // two credits landing at once cannot clobber one another the way
            // read-modify-write through the model could.
            Users::where('id', $topup->user_id)->incrementEach([
                'coin_wallet' => $topup->coins,
                'coin_purchased_lifetime' => $topup->coins,
            ]);

            Log::info("TopUpReconciler: credited {$topup->coins} coins to user {$topup->user_id} for top-up {$topup->id}");
            return true;
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
