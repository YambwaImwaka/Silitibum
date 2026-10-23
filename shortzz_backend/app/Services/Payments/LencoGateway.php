<?php

namespace App\Services\Payments;

use App\Models\GlobalFunction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Verified against https://lenco-api.readme.io/v2.0/reference/ (collections,
// transfers, resolve and webhooks pages).
//
// HISTORY, so nobody re-derives the old conclusions: this class first
// targeted Lenco's v1 API, which had no collections endpoint and paid out
// only to NUBAN bank accounts — so it was documented as unusable for both the
// web top-up and mobile-money payouts. v2 added both for Zambia: collections
// (/collections/mobile-money) and transfers (/transfers/mobile-money,
// operators airtel/mtn/zamtel), plus /resolve/mobile-money, which returns the
// name a number is registered to. Lenco is now valid as BOTH
// active_collection_provider and active_payout_provider; payouts also need
// lenco_account_id (the Lenco account the money is sent from).
class LencoGateway implements PaymentGatewayInterface
{
    private string $apiKey;
    private ?string $accountId;
    private string $baseUrl = 'https://api.lenco.co/access/v2';

    public function __construct(string $apiKey, ?string $accountId = null)
    {
        $this->apiKey = $apiKey;
        $this->accountId = $accountId ?: null;
    }

    private function client()
    {
        return Http::withToken($this->apiKey)->baseUrl($this->baseUrl);
    }

    public function charge(string $phone, float $amount, string $currency, string $reference): array
    {
        $operator = $this->operatorFor($phone);
        if ($operator === null) {
            Log::error('LencoGateway::charge could not map phone to a mobile money operator: ' . $phone);
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'unsupported_operator'];
        }

        try {
            $response = $this->client()->post('/collections/mobile-money', [
                'amount' => $amount,
                'reference' => $reference,
                'phone' => $this->localPhone($phone),
                'operator' => $operator,
                'country' => 'zm',
                // 'merchant' (the default) means we absorb the transaction
                // fee rather than adding it to what the buyer is charged —
                // the coin package price the user was shown is what they pay.
                'bearer' => 'merchant',
            ]);
            if (!$response->successful()) {
                return GatewayResult::fromInitiationError($response, null);
            }
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            // The push may well have gone out — leave the top-up open for the
            // sweep, which asks Lenco by our 'topup_{id}' reference.
            Log::error('LencoGateway::charge failed: ' . $e->getMessage());
            return GatewayResult::unknown(null);
        }
    }

    // POST /transfers/mobile-money (v2, zm/mw). $reference may only contain
    // [A-Za-z0-9._-] and must never repeat — WalletController sends
    // {request_number}-{attempt}. Lenco answers a reused one with "Duplicate
    // reference", which GatewayResult keeps open rather than failing.
    public function payout(string $phone, float $amount, string $currency, string $reference): array
    {
        if ($this->accountId === null) {
            Log::error('LencoGateway::payout: lenco_account_id is not configured');
            return GatewayResult::make(GatewayResult::FAILED, $reference, 'lenco_account_id_missing');
        }
        $operator = $this->operatorFor($phone);
        if ($operator === null) {
            return GatewayResult::make(GatewayResult::FAILED, $reference, 'unsupported_operator');
        }
        try {
            $response = $this->client()->post('/transfers/mobile-money', [
                'accountId' => $this->accountId,
                'amount' => round($amount, 2),
                'reference' => $reference,
                'narration' => 'Silitibum creator payout',
                'phone' => $this->localPhone($phone),
                'operator' => $operator,
                'country' => 'zm',
            ]);
            if (!$response->successful()) {
                return GatewayResult::fromInitiationError($response, $reference);
            }
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            // The transfer may have been created: never refund on this —
            // payouts:reconcile asks /transfers/status by our reference.
            Log::error('LencoGateway::payout failed: ' . $e->getMessage());
            return GatewayResult::unknown($reference);
        }
    }

    public function verifyPayout(string $reference): array
    {
        try {
            $response = $this->client()->get('/transfers/status/' . urlencode($reference));
            if (!$response->successful() || !$response->json('status')) {
                return GatewayResult::fromVerifyError($response, $reference);
            }
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            Log::error('LencoGateway::verifyPayout failed: ' . $e->getMessage());
            return GatewayResult::unknown($reference);
        }
    }

    /// The name a mobile-money number is registered to (operators register
    /// SIMs against an NRC), or null when Lenco cannot say. Used to confirm a
    /// creator's payout number is really theirs; the result is never shown to
    /// the app, so this cannot be used to look up strangers' names.
    public function resolveMobileMoney(string $phone): ?string
    {
        $operator = $this->operatorFor($phone);
        if ($operator === null) {
            return null;
        }
        try {
            $response = $this->client()->post('/resolve/mobile-money', [
                'phone' => $this->localPhone($phone),
                'operator' => $operator,
                'country' => 'zm',
            ]);
            if (!$response->successful() || !$response->json('status')) {
                return null;
            }
            $name = trim((string) $response->json('data.accountName'));
            return $name === '' ? null : $name;
        } catch (\Throwable $e) {
            Log::error('LencoGateway::resolveMobileMoney failed: ' . $e->getMessage());
            return null;
        }
    }

    public function verify(string $reference): array
    {
        try {
            $response = $this->client()->get('/collections/status/' . urlencode($reference));
            // "Collection details was not found" (errorCode 10) arrives as a
            // 4xx with status:false. It means Lenco has nothing under this
            // reference YET or at all — not that a payment failed.
            if (!$response->successful() || !$response->json('status')) {
                return GatewayResult::fromVerifyError($response, $reference);
            }
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            Log::error('LencoGateway::verify failed: ' . $e->getMessage());
            return GatewayResult::unknown($reference);
        }
    }

    // Prefix -> operator lives in MobileMoney (shared with the withdrawal
    // form). Lenco's v2 docs show the national '0971234567' form for zm, with
    // the country sent separately.
    private function operatorFor(string $phone): ?string
    {
        return MobileMoney::operatorFor($phone);
    }

    private function localPhone(string $phone): string
    {
        return MobileMoney::localPhone($phone);
    }

    // Callers have already handled transport and HTTP-level errors; this only
    // ever sees a 2xx. A 2xx with status:false is Lenco rejecting the request.
    private function mapResponse($response): array
    {
        if (!$response->json('status')) {
            return GatewayResult::make(GatewayResult::FAILED, null, $response->json('message'));
        }

        // v2 collection statuses: pending | successful | failed | pay-offline
        // | otp-required | 3ds-auth-required; transfers: pending | successful
        // | failed. Everything that isn't terminal maps to 'processing' —
        // 'pay-offline' in particular is the normal, expected result of a
        // mobile-money charge (the customer still has to approve the prompt
        // on their handset), NOT a failure.
        $txnStatus = $response->json('data.status');
        $mapped = $txnStatus === 'successful' ? 'completed'
            : ($txnStatus === 'failed' ? 'failed' : 'processing');

        $result = [
            'success' => $mapped !== 'failed',
            'status' => $mapped,
            'provider_reference' => $response->json('data.lencoReference') ?? $response->json('data.reference'),
            'message' => $response->json('data.reasonForFailure') ?? $response->json('message'),
        ];
        // Transfers report what Lenco charged us for them.
        if ($response->json('data.fee') !== null) {
            $result['fee'] = (string) $response->json('data.fee');
        }
        return $result;
    }
}
