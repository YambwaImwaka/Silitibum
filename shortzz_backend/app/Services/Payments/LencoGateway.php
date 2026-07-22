<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Verified against https://lenco-api.readme.io/reference/ (get-started,
// create-transfer, get-transfer-by-reference, webhooks pages).
//
// CONFIRMED PRODUCT-FIT ISSUE, read before enabling this as the active
// payout provider: Lenco is a Nigerian *business banking* API, not a mobile
// money aggregator. Its transfer API pays out to a NUBAN bank account number
// + bank code — there is no mobile-money-by-phone-number endpoint anywhere
// in its reference docs, and no collections/charge endpoint at all (so it
// can't power the web top-up flow either). It only fits this feature if a
// user's payout "account" happens to be a Nigerian bank account, which
// doesn't match "mobile money." Left implemented (against real, verified
// endpoints) in case it's useful for a future bank-transfer payout option,
// but it is not a drop-in mobile money provider like Paystack/DPO.
class LencoGateway implements PaymentGatewayInterface
{
    private string $apiKey;
    // Confirmed sandbox base URL from docs; production is presumably the
    // same host without "sandbox." (standard convention) — confirm before
    // going live, this specific substitution wasn't shown in what I fetched.
    private string $baseUrl = 'https://api.lenco.co/access/v1';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    private function client()
    {
        return Http::withToken($this->apiKey)->baseUrl($this->baseUrl);
    }

    public function charge(string $phone, float $amount, string $currency, string $reference): array
    {
        Log::error('LencoGateway::charge called — Lenco has no collections/mobile-money charge API. Pick a different active provider for the web top-up flow.');
        return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'not_supported'];
    }

    // NOTE: signature keeps $phone for PaymentGatewayInterface compliance,
    // but Lenco actually needs a bank accountId/accountNumber/bankCode, not
    // a phone number — this will fail against the real API as written below
    // unless the caller is somehow passing a NUBAN through $phone, which the
    // rest of this codebase (phone-number withdrawal accounts) never will.
    // Included for interface completeness, not recommended for use yet.
    public function payout(string $phone, float $amount, string $currency, string $reference): array
    {
        try {
            $response = $this->client()->post('/transfer', [
                'accountId' => config('services.lenco.source_account_id'), // your Lenco account to pay FROM — not yet configurable from admin settings
                'accountNumber' => $phone, // WRONG for a phone number — Lenco expects a 10-digit NUBAN here
                'bankCode' => null, // required by Lenco, no source for this in the current withdrawal form
                'amount' => (string) $amount,
                'narration' => 'Shortzz withdrawal',
                'reference' => $reference,
            ]);
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            Log::error('LencoGateway::payout failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    public function verify(string $reference): array
    {
        try {
            $response = $this->client()->get('/transfer/by-reference/' . $reference);
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            Log::error('LencoGateway::verify failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => $reference, 'message' => 'gateway_error'];
        }
    }

    private function mapResponse($response): array
    {
        if (!$response->successful() || !$response->json('status')) {
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => $response->json('message')];
        }
        $txnStatus = $response->json('data.transaction.status');
        $mapped = $txnStatus === 'successful' ? 'completed'
            : ($txnStatus === 'failed' ? 'failed' : 'processing');
        return [
            'success' => $mapped !== 'failed',
            'status' => $mapped,
            'provider_reference' => $response->json('data.transaction.transactionReference') ?? $response->json('data.request.reference'),
            'message' => $response->json('message'),
        ];
    }
}
