<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Verified against https://developer.flutterwave.com/ (authentication,
// mobile-money collections, mobile-money-1 payouts, webhooks, customers,
// payment-methods reference pages) — the strongest fit of the four
// providers here, since Flutterwave explicitly supports mobile money for
// BOTH collections and payouts with real phone-number-based recipients,
// unlike Lenco (bank-only, no mobile money at all) or DPO (collections
// only, no payout API whatsoever). Recommend this as the primary provider
// if only one needs picking to start with.
//
// UNVERIFIED: the production base URL. Docs confirmed the sandbox host as
// https://developersandbox-api.flutterwave.com in live endpoint examples,
// and a template "https://{{ENVIRONMENT}}.flutterwave.com" on the auth
// page — https://api.flutterwave.com below is inferred from that template
// plus Flutterwave's well-known v3 API host, not fetched directly. Confirm
// in their dashboard before going live.
class FlutterwaveGateway implements PaymentGatewayInterface
{
    private string $secretKey;
    private string $baseUrl = 'https://api.flutterwave.com';

    public function __construct(string $secretKey)
    {
        $this->secretKey = $secretKey;
    }

    private function client()
    {
        return Http::withToken($this->secretKey)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->baseUrl($this->baseUrl);
    }

    // $network / $countryCode: mobile network name (e.g. "MTN") and dialing
    // country code (e.g. "260" for Zambia) — not part of the shared
    // interface since only Flutterwave/DPO need this split; callers must
    // resolve these from the phone number before calling.
    public function charge(string $phone, float $amount, string $currency, string $reference, ?string $network = null, ?string $countryCode = null): array
    {
        try {
            $customer = $this->client()->post('/customers', [
                'email' => $reference . '@placeholder.invalid', // Flutterwave requires an email; not collected from mobile-money users.
            ]);
            if (!$customer->successful()) {
                Log::error('FlutterwaveGateway::charge customer creation failed: ' . $customer->body());
                return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'customer_error'];
            }
            $customerId = $customer->json('data.id');

            $paymentMethod = $this->client()->post('/payment-methods', [
                'type' => 'mobile_money',
                'customer_id' => $customerId,
                'mobile_money' => [
                    'network' => $network,
                    'country_code' => $countryCode,
                    'phone_number' => $phone,
                ],
            ]);
            if (!$paymentMethod->successful()) {
                Log::error('FlutterwaveGateway::charge payment method creation failed: ' . $paymentMethod->body());
                return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'payment_method_error'];
            }
            $paymentMethodId = $paymentMethod->json('data.id');

            $charge = $this->client()->post('/charges', [
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
            ]);
            if (!$charge->successful()) {
                Log::error('FlutterwaveGateway::charge failed: ' . $charge->body());
                return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
            }
            $status = $charge->json('data.status');
            $mapped = $status === 'succeeded' ? 'completed' : ($status === 'failed' ? 'failed' : 'processing');
            return [
                'success' => $mapped !== 'failed',
                'status' => $mapped,
                'provider_reference' => $charge->json('data.id'),
                'message' => $charge->json('data.next_action.payment_instruction.note'),
            ];
        } catch (\Throwable $e) {
            Log::error('FlutterwaveGateway::charge failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    public function payout(string $phone, float $amount, string $currency, string $reference, ?string $network = null, string $recipientName = 'Shortzz user'): array
    {
        try {
            $response = $this->client()->post('/direct-transfers', [
                'action' => 'instant',
                'type' => 'mobile_money',
                'reference' => $reference,
                'narration' => 'Shortzz withdrawal',
                'payment_instruction' => [
                    'source_currency' => $currency,
                    'destination_currency' => $currency,
                    'amount' => ['value' => $amount, 'applies_to' => 'destination_currency'],
                    'recipient' => [
                        'name' => $recipientName,
                        'mobile_money' => [
                            'network' => $network,
                            'msisdn' => $phone,
                        ],
                    ],
                ],
            ]);
            return $this->mapTransferResponse($response);
        } catch (\Throwable $e) {
            Log::error('FlutterwaveGateway::payout failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'gateway_error'];
        }
    }

    public function verify(string $reference): array
    {
        try {
            // Charges and transfers are different resources — try transfer
            // first (the payout path is what the rest of Phase 2 calls this
            // for), fall back to charge lookup for Phase 3's top-up flow.
            $response = $this->client()->get('/direct-transfers/' . $reference);
            if ($response->status() === 404) {
                $response = $this->client()->get('/charges/' . $reference);
                $status = $response->json('data.status');
                $mapped = $status === 'succeeded' ? 'completed' : ($status === 'failed' ? 'failed' : 'processing');
                return [
                    'success' => $mapped === 'completed',
                    'status' => $mapped,
                    'provider_reference' => $response->json('data.id'),
                    'message' => $response->json('message'),
                ];
            }
            return $this->mapTransferResponse($response);
        } catch (\Throwable $e) {
            Log::error('FlutterwaveGateway::verify failed: ' . $e->getMessage());
            return ['success' => false, 'status' => 'failed', 'provider_reference' => $reference, 'message' => 'gateway_error'];
        }
    }

    private function mapTransferResponse($response): array
    {
        if (!$response->successful()) {
            return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => $response->json('message')];
        }
        $status = $response->json('data.status');
        // Confirmed: initiation returns "NEW". SUCCESSFUL confirmed too.
        // Exact failure-state string NOT confirmed from docs — treat
        // anything that isn't NEW/SUCCESSFUL/PENDING-like as processing
        // rather than guessing a failure string wrong and never refunding
        // a genuinely failed payout; verify() gets called again on the
        // next webhook/retry until it resolves.
        $mapped = $status === 'SUCCESSFUL' ? 'completed'
            : (in_array($status, ['FAILED', 'CANCELLED']) ? 'failed' : 'processing');
        return [
            'success' => $mapped !== 'failed',
            'status' => $mapped,
            'provider_reference' => $response->json('data.reference') ?? $response->json('data.id'),
            'message' => $response->json('message'),
        ];
    }
}
