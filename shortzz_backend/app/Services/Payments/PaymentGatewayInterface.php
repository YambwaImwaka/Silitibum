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

    // Re-checks a previously-initiated CHARGE by OUR reference (not the
    // provider's) — used by the top-up webhook and the topups:reconcile sweep
    // to confirm a payment server-side rather than trusting a webhook body.
    // Same result shape; see GatewayResult for what 'failed' may mean.
    public function verify(string $reference): array;

    // Re-checks a previously-initiated PAYOUT by our reference. Separate from
    // verify() because providers keep transfers and collections apart (Lenco:
    // /transfers/status vs /collections/status) — asking the wrong one says
    // "not found" about a transfer that exists.
    public function verifyPayout(string $reference): array;
}
