<?php

namespace App\Services\Payments;

use App\Models\GlobalFunction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Verified against https://lenco-api.readme.io/v2.0/reference/ (collections
// and webhooks pages).
//
// HISTORY, so nobody re-derives the old conclusion: this class previously
// targeted Lenco's v1 API and documented that Lenco "has no collections
// endpoint" and could not power the web top-up flow. That was true of v1 —
// it is NOT true of v2, which added /collections/mobile-money for Zambia
// (zm) and Malawi (mw) with airtel/mtn/zamtel/tnm operators. Collections are
// now implemented below and Lenco is a valid active_collection_provider.
//
// Payouts are a different story and the old warning still stands there —
// see payout().
class LencoGateway implements PaymentGatewayInterface
{
    private string $apiKey;
    private string $baseUrl = 'https://api.lenco.co/access/v2';

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
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            Log::error('LencoGateway::charge failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    // NOTE: unchanged from the v1 implementation and still not recommended.
    // Lenco's transfer API pays out to a NUBAN bank account number + bank
    // code; there is no mobile-money-by-phone payout endpoint. This will fail
    // against the real API unless the caller passes a bank account number
    // through $phone, which the rest of this codebase (phone-number
    // withdrawal accounts) never does. Collections above are unaffected —
    // Lenco is fine as active_collection_provider and wrong as
    // active_payout_provider.
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
            $response = $this->client()->get('/collections/status/' . urlencode($reference));
            return $this->mapResponse($response);
        } catch (\Throwable $e) {
            Log::error('LencoGateway::verify failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => $reference, 'message' => 'gateway_error'];
        }
    }

    // Zambian mobile prefixes, national form: MTN 096/076, Airtel 097/077,
    // Zamtel 095/075. Lenco requires the operator explicitly and there is no
    // lookup endpoint for it, so it has to come from the prefix — asking the
    // user to pick their network on top of typing their number is exactly the
    // kind of friction the top-up page is trying to avoid.
    private function operatorFor(string $phone): ?string
    {
        $national = $this->localPhone($phone);
        $prefix = substr($national, 0, 3);
        return match ($prefix) {
            '096', '076' => 'mtn',
            '097', '077' => 'airtel',
            '095', '075' => 'zamtel',
            default => null,
        };
    }

    // UNVERIFIED, confirm on the first live charge: Lenco's own reference
    // docs don't state the expected phone format, and the two community
    // implementations disagree — the Node SDK's example passes a national
    // '0971234567' while another gateway passes an international
    // '2609XXXXXXXX'. Going with the national form because it's what Lenco's
    // own SDK shows and because `country` is already sent separately, so the
    // number needn't carry the code. If a live charge is rejected for an
    // invalid phone, this one method is the only thing that needs to change.
    private function localPhone(string $phone): string
    {
        $msisdn = GlobalFunction::canonicalMsisdn($phone) ?? preg_replace('/[^0-9]/', '', $phone);
        $code = (string) config('app.default_country_code');
        if ($code !== '' && str_starts_with($msisdn, $code)) {
            return '0' . substr($msisdn, strlen($code));
        }
        return $msisdn;
    }

    private function mapResponse($response): array
    {
        if (!$response->successful() || !$response->json('status')) {
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => $response->json('message')];
        }

        // v2 collection statuses: pending | successful | failed | pay-offline
        // | otp-required | 3ds-auth-required. Everything that isn't terminal
        // maps to 'processing' — 'pay-offline' in particular is the normal,
        // expected result of a mobile-money charge (the customer still has to
        // approve the prompt on their handset), NOT a failure.
        $txnStatus = $response->json('data.status');
        $mapped = $txnStatus === 'successful' ? 'completed'
            : ($txnStatus === 'failed' ? 'failed' : 'processing');

        return [
            'success' => $mapped !== 'failed',
            'status' => $mapped,
            'provider_reference' => $response->json('data.lencoReference') ?? $response->json('data.reference'),
            'message' => $response->json('data.reasonForFailure') ?? $response->json('message'),
        ];
    }
}
