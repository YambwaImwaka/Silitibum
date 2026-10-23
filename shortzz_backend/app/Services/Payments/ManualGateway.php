<?php

namespace App\Services\Payments;

// A payout provider that deliberately does nothing automatically.
//
// Every real provider here either settles a payout or fails it. Zambian
// mobile-money disbursement is not something the current providers can
// actually do: LencoGateway::payout() is the active one, and Lenco's transfer
// API pays a NUBAN bank account number + bank code, while this app's
// withdrawal form only ever collects a phone number (see the note on that
// method). The result was that every withdrawal request failed instantly and
// refunded, so nobody could withdraw at all.
//
// Rather than leave a provider wired up that cannot succeed, this gateway
// parks a payout in 'processing' and lets an admin settle it out of band
// (bank app, Lenco dashboard, cash) and then close it with the Complete or
// Reject buttons that already exist on the Withdrawals page — see
// WalletController::completeWithdrawal / rejectWithdrawal, which handle the
// coin accounting correctly on both paths.
//
// Collections are unaffected and must NOT use this: Lenco's collections API
// works and is verified, so active_collection_provider stays 'lenco'. This
// class only makes sense as active_payout_provider.
class ManualGateway implements PaymentGatewayInterface
{
    // No automated collection path. Returning 'failed' rather than throwing
    // keeps the caller's normal refund/abort handling intact if this is ever
    // mis-set as the collection provider.
    public function charge(string $phone, float $amount, string $currency, string $reference): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'provider_reference' => null,
            'message' => 'manual gateway does not support collections — set active_collection_provider to a real provider',
        ];
    }

    // 'processing', never 'completed': the money has NOT moved yet. Reporting
    // completion here would mark the request paid and strand the user's coins
    // with nothing sent. The admin decides the outcome.
    public function payout(string $phone, float $amount, string $currency, string $reference): array
    {
        return [
            'success' => true,
            'status' => 'processing',
            'provider_reference' => null,
            'message' => 'queued for manual settlement by an admin',
        ];
    }

    // A re-check has nothing to ask: there is no provider holding state. Stay
    // 'processing' so WalletController::recheckWithdrawal leaves the request
    // exactly as it found it instead of completing or failing (and refunding)
    // something an admin may already have paid by hand.
    public function verify(string $reference): array
    {
        return [
            'success' => true,
            'status' => 'processing',
            'provider_reference' => null,
            'message' => 'awaiting manual settlement',
        ];
    }

    public function verifyPayout(string $reference): array
    {
        return $this->verify($reference);
    }
}
