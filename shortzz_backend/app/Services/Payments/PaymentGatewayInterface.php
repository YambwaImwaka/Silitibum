<?php

namespace App\Services\Payments;

// Every mobile-money provider (DPO, Lenco, Paystack, ...) implements this so
// the rest of the app never needs to know which one is active — see
// PaymentGatewayFactory::active(), which reads the admin's choice from
// tbl_settings.active_payout_provider and returns the matching instance.
// Add a new provider by adding a class here + one settings-form section;
// nothing else in the app changes.
interface PaymentGatewayInterface
{
    // Charges a customer's mobile money account (collections — used by the
    // web top-up flow). Most providers push a PIN-approval prompt to the
    // customer's phone and confirm asynchronously via webhook, so a
    // 'processing' result is normal and expected, not a failure.
    //
    // Returns ['success' => bool, 'status' => 'completed'|'processing'|'failed',
    //          'provider_reference' => string|null, 'message' => string|null]
    public function charge(string $phone, float $amount, string $currency, string $reference): array;

    // Sends money to a user's mobile money account (payouts). Same result
    // shape as charge().
    public function payout(string $phone, float $amount, string $currency, string $reference): array;

    // Re-checks a previously-initiated charge or payout by OUR reference
    // (not the provider's) — used both for manual admin re-checks and, where
    // a provider's webhook payload is thin, to confirm details server-side
    // rather than trusting the webhook body alone. Same result shape.
    public function verify(string $reference): array;
}
