<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// VERIFY BEFORE PRODUCTION USE: written from general knowledge of Paystack's
// API, not fetched from their live docs (this environment's outbound network
// access couldn't reach paystack.com to confirm — 403 from their docs site).
// Confirm against https://paystack.com/docs before this ever touches real
// money: exact field names, whether mobile-money transfer recipients need a
// bank_code for your country/telco, and — importantly — whether Paystack
// even operates in your country at all (as of general knowledge, Paystack's
// coverage is Nigeria/Ghana/South Africa/Kenya; confirm Zambia support
// before relying on this one).
class PaystackGateway implements PaymentGatewayInterface
{
    private string $secretKey;
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    private function client()
    {
        return Http::withToken($this->secretKey)->baseUrl($this->baseUrl);
    }

    public function charge(string $phone, float $amount, string $currency, string $reference): array
    {
        try {
            // Paystack amounts are in the smallest currency unit (kobo/pesewas).
            $response = $this->client()->post('/charge', [
                'email' => $reference . '@placeholder.invalid', // Paystack requires an email; not collected from mobile-money users.
                'amount' => (int) round($amount * 100),
                'currency' => $currency,
                'reference' => $reference,
                'mobile_money' => [
                    'phone' => $phone,
                    'provider' => null, // VERIFY: Paystack expects a provider code (e.g. "mtn"/"vod"/"tgo" for Ghana) — map from the phone's network before going live.
                ],
            ]);
            return $this->mapChargeResponse($response);
        } catch (\Throwable $e) {
            Log::error('PaystackGateway::charge failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    public function payout(string $phone, float $amount, string $currency, string $reference): array
    {
        try {
            // Payouts need a transfer recipient created first.
            $recipient = $this->client()->post('/transferrecipient', [
                'type' => 'mobile_money',
                'name' => $phone,
                'account_number' => $phone,
                'currency' => $currency,
                // VERIFY: mobile-money recipients on Paystack typically also
                // require a bank_code identifying the telco — confirm the
                // exact code list for your country before going live.
            ]);
            if (!$recipient->successful() || !($recipient->json('status'))) {
                Log::error('PaystackGateway::payout recipient creation failed: ' . $recipient->body());
                return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'recipient_error'];
            }
            $recipientCode = $recipient->json('data.recipient_code');

            $response = $this->client()->post('/transfer', [
                'source' => 'balance',
                'amount' => (int) round($amount * 100),
                'recipient' => $recipientCode,
                'reason' => 'Shortzz withdrawal',
                'reference' => $reference,
            ]);
            return $this->mapTransferResponse($response);
        } catch (\Throwable $e) {
            Log::error('PaystackGateway::payout failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    public function verify(string $reference): array
    {
        try {
            $response = $this->client()->get('/transfer/verify/' . $reference);
            return $this->mapTransferResponse($response);
        } catch (\Throwable $e) {
            Log::error('PaystackGateway::verify failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    private function mapChargeResponse($response): array
    {
        if (!$response->successful()) {
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => $response->json('message')];
        }
        $status = $response->json('data.status');
        // 'pay_offline'/'send_otp'/'pending' all mean "customer must approve
        // on their phone" — confirm the exact set of in-flight status
        // strings against current Paystack docs.
        $mapped = in_array($status, ['success']) ? 'completed'
            : (in_array($status, ['failed', 'abandoned']) ? 'failed' : 'processing');
        return [
            'success' => $mapped !== 'failed',
            'status' => $mapped,
            'provider_reference' => $response->json('data.reference'),
            'message' => $response->json('message'),
        ];
    }

    private function mapTransferResponse($response): array
    {
        if (!$response->successful()) {
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => $response->json('message')];
        }
        $status = $response->json('data.status');
        $mapped = $status === 'success' ? 'completed' : ($status === 'failed' || $status === 'reversed' ? 'failed' : 'processing');
        return [
            'success' => $mapped !== 'failed',
            'status' => $mapped,
            'provider_reference' => $response->json('data.transfer_code') ?? $response->json('data.reference'),
            'message' => $response->json('message'),
        ];
    }
}
