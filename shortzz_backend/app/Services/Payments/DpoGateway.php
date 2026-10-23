<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Verified against https://docs.dpopay.com/dpo-pay-by-network/reference/
// (createToken, charge-token-mobile, transaction-status-lookup pages).
//
// CONFIRMED LIMITATION: DPO has no disbursement/payout API — it's a
// collections-only gateway (their own docs describe it as a "hosted
// checkout experience" with no outbound-transfer endpoint anywhere in the
// reference nav). payout() below fails clearly rather than pretending to
// work — pick a different active_payout_provider for the payout side.
//
// Charge flow is two DPO calls: createToken (open a transaction), then
// ChargeTokenMobile (actually push a mobile-money charge request to the
// customer's phone) — confirmed via docs, not the single-call flow this
// file originally guessed. NOT YET VERIFIED: the exact MNO (network) codes
// DPO expects for Zambian MTN/Airtel Money specifically — the docs example
// used "mpesa"/"SafaricomC2B" for Kenya; confirm Zambia's exact MNO strings
// with DPO support or their full network list before going live.
class DpoGateway implements PaymentGatewayInterface
{
    private string $companyToken;
    private string $serviceType;
    private string $baseUrl = 'https://secure.3gdirectpay.com/API/v6/';

    public function __construct(string $companyToken, string $serviceType)
    {
        $this->companyToken = $companyToken;
        $this->serviceType = $serviceType;
    }

    // $mno / $mnoCountry: the mobile network operator + country DPO expects
    // (e.g. "MTN"/"zambia") — not part of the shared interface signature
    // since no other provider needs this split; callers using DPO must
    // resolve these from the phone number before calling charge().
    public function charge(string $phone, float $amount, string $currency, string $reference, ?string $mno = null, ?string $mnoCountry = null): array
    {
        try {
            $token = $this->createToken($amount, $currency, $reference);
            if (!$token) {
                return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'token_creation_failed'];
            }

            $xml = '<?xml version="1.0" encoding="utf-8"?>'
                . '<API3G>'
                . '<CompanyToken>' . htmlspecialchars($this->companyToken) . '</CompanyToken>'
                . '<Request>ChargeTokenMobile</Request>'
                . '<TransactionToken>' . htmlspecialchars($token) . '</TransactionToken>'
                . '<PhoneNumber>' . htmlspecialchars($phone) . '</PhoneNumber>'
                . '<MNO>' . htmlspecialchars($mno ?? '') . '</MNO>'
                . '<MNOcountry>' . htmlspecialchars($mnoCountry ?? '') . '</MNOcountry>'
                . '</API3G>';

            $response = Http::withBody($xml, 'application/xml')->post($this->baseUrl);
            $data = @simplexml_load_string($response->body());
            // ChargeTokenMobile responds with StatusCode (v6) — 000 means
            // the push to the customer's phone was sent, NOT that they've
            // approved it yet. Actual payment confirmation only comes from
            // verify() (or the push-payment callback triggering a verify()
            // call) once the customer enters their mobile money PIN.
            $status = (string) ($data->StatusCode ?? '');
            if ($data === false || $status !== '000') {
                Log::error('DpoGateway::charge (ChargeTokenMobile) failed: ' . $response->body());
                return ['success' => false, 'status' => 'failed', 'provider_reference' => $token, 'message' => (string) ($data->ResultExplanation ?? 'gateway_error')];
            }

            return ['success' => true, 'status' => 'processing', 'provider_reference' => $token, 'message' => 'push_sent'];
        } catch (\Throwable $e) {
            Log::error('DpoGateway::charge failed: ' . $e->getMessage());
            return GatewayResult::unknown(null);
        }
    }

    public function payout(string $phone, float $amount, string $currency, string $reference): array
    {
        Log::error('DpoGateway::payout called — DPO has no disbursement API (collections only). Pick a different active_payout_provider for payouts.');
        return ['success' => false, 'status' => 'failed', 'provider_reference' => null, 'message' => 'not_supported'];
    }

    // Always re-verify server-side rather than trusting DPO's callback body
    // directly — DPO's own docs describe no signature/authenticity check on
    // the callback, just "log the fields, then call verifyToken yourself."
    public function verify(string $reference): array
    {
        try {
            $xml = '<?xml version="1.0" encoding="utf-8"?>'
                . '<API3G>'
                . '<CompanyToken>' . htmlspecialchars($this->companyToken) . '</CompanyToken>'
                . '<Request>verifyToken</Request>'
                . '<TransactionToken>' . htmlspecialchars($reference) . '</TransactionToken>'
                . '</API3G>';

            $response = Http::withBody($xml, 'application/xml')->post($this->baseUrl);
            $data = @simplexml_load_string($response->body());
            if (!$response->successful() || $data === false) {
                return GatewayResult::unknown($reference, 'unreadable_status_response');
            }
            $result = (string) $data->Result;
            // Confirmed codes (docs): 000 paid, 001 authorized, 900 not
            // paid yet, 901 declined, 903 past time limit, 904 cancelled.
            // Only those last three are verdicts. Any other code is an error
            // about the REQUEST (bad token, missing field), which says nothing
            // about whether the customer paid.
            $mapped = in_array($result, ['000', '001']) ? GatewayResult::COMPLETED
                : (in_array($result, ['901', '903', '904']) ? GatewayResult::FAILED : GatewayResult::PROCESSING);
            return GatewayResult::make($mapped, $reference, (string) ($data->ResultExplanation ?? null));
        } catch (\Throwable $e) {
            Log::error('DpoGateway::verify failed: ' . $e->getMessage());
            return GatewayResult::unknown($reference);
        }
    }

    // DPO makes no payouts, so there is never a DPO transfer to look up.
    public function verifyPayout(string $reference): array
    {
        return GatewayResult::unknown($reference, 'not_supported');
    }

    private function createToken(float $amount, string $currency, string $reference): ?string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>'
            . '<API3G>'
            . '<CompanyToken>' . htmlspecialchars($this->companyToken) . '</CompanyToken>'
            . '<Request>createToken</Request>'
            . '<Transaction>'
            . '<PaymentAmount>' . number_format($amount, 2, '.', '') . '</PaymentAmount>'
            . '<PaymentCurrency>' . htmlspecialchars($currency) . '</PaymentCurrency>'
            . '<CompanyRef>' . htmlspecialchars($reference) . '</CompanyRef>'
            . '<PTL>5</PTL>'
            . '</Transaction>'
            . '<Services>'
            . '<Service>'
            . '<ServiceType>' . htmlspecialchars($this->serviceType) . '</ServiceType>'
            . '<ServiceDescription>Shortzz coin top-up</ServiceDescription>'
            . '<ServiceDate>' . now()->format('Y/m/d H:i') . '</ServiceDate>'
            . '</Service>'
            . '</Services>'
            . '</API3G>';

        $response = Http::withBody($xml, 'application/xml')->post($this->baseUrl);
        $data = @simplexml_load_string($response->body());
        if ($data === false || (string) $data->Result !== '000') {
            Log::error('DpoGateway::createToken failed: ' . $response->body());
            return null;
        }
        return (string) $data->TransToken;
    }
}
