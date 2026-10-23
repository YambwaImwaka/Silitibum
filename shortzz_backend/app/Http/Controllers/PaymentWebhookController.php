<?php

namespace App\Http\Controllers;

use App\Models\Constants;
use App\Models\CoinTopUpRequests;
use App\Models\GlobalSettings;
use App\Models\RedeemRequests;
use App\Models\Users;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Payments\TopUpReconciler;
use App\Services\Wallet\Withdrawals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// One method per provider — each has a genuinely different authenticity
// scheme (Paystack: HMAC-SHA512 of the body with the secret key directly;
// Lenco: HMAC-SHA512 with SHA256(api_key) as the derived key; DPO: no
// signature at all, callback is just a "check now" trigger). None of these
// are interchangeable, so there's no single generic "verify webhook"
// middleware that would actually be correct for all three — verification is
// done per-method here instead.
//
// Principle used throughout: the webhook body itself is never trusted for
// the actual status — every handler re-calls the gateway's verify() to get
// the authoritative status from the provider directly before crediting or
// finalizing anything. The webhook's only job is telling us WHEN to check,
// not WHAT happened.
class PaymentWebhookController extends Controller
{
    public function paystack(Request $request)
    {
        $settings = GlobalSettings::first();
        $secret = $settings->paystack_secret_key;
        $signature = $request->header('x-paystack-signature');
        $computed = hash_hmac('sha512', $request->getContent(), $secret ?? '');
        if (empty($secret) || !hash_equals($computed, (string) $signature)) {
            Log::error('PaymentWebhookController::paystack bad signature');
            return response()->json(['status' => false], 401);
        }

        $reference = $request->input('data.reference') ?? $request->input('data.transfer_code');
        return $this->resolvePaymentReference($reference, 'paystack', $settings);
    }

    public function lenco(Request $request)
    {
        $settings = GlobalSettings::first();
        $apiKey = $settings->lenco_api_key;
        $signature = $request->header('X-Lenco-Signature');
        $hashKey = hash('sha256', $apiKey ?? '');
        $computed = hash_hmac('sha512', $request->getContent(), $hashKey);
        if (empty($apiKey) || !hash_equals($computed, (string) $signature)) {
            Log::error('PaymentWebhookController::lenco bad signature');
            return response()->json(['status' => false], 401);
        }

        // v2 collection.* payloads carry 'reference' (the one WE sent, i.e.
        // "topup_{id}") and 'lencoReference' (theirs). Prefer ours — it's what
        // resolvePaymentReference matches on, and verify() looks up by our
        // reference too. The v1 names this used to read (transactionReference/
        // clientReference) are not present in v2 payloads at all.
        $reference = $request->input('data.reference') ?? $request->input('data.lencoReference');
        return $this->resolvePaymentReference($reference, 'lenco', $settings);
    }

    public function flutterwave(Request $request)
    {
        $settings = GlobalSettings::first();
        $secret = $settings->flutterwave_secret_key;
        $signature = $request->header('flutterwave-signature');
        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret ?? '', true));
        if (empty($secret) || !hash_equals($computed, (string) $signature)) {
            Log::error('PaymentWebhookController::flutterwave bad signature');
            return response()->json(['status' => false], 401);
        }

        $reference = $request->input('data.reference') ?? $request->input('data.id');
        return $this->resolvePaymentReference($reference, 'flutterwave', $settings);
    }

    public function dpo(Request $request)
    {
        // No signature scheme — DPO's own guidance is to treat the callback
        // as a trigger and re-verify server-side, which resolvePaymentReference
        // already does for every provider. TransactionToken is what
        // DpoGateway used as provider_reference when the charge was created.
        $settings = GlobalSettings::first();
        $reference = $request->input('TransactionToken') ?? $request->input('CompanyRef');
        return $this->resolvePaymentReference($reference, 'dpo', $settings);
    }

    // A reference could belong to either side — a payout (RedeemRequests)
    // or a web top-up charge (CoinTopUpRequests) — since the same provider
    // can be configured as both active_payout_provider and
    // active_collection_provider. Try both; whichever matches wins.
    private function resolvePaymentReference(?string $reference, string $provider, GlobalSettings $settings)
    {
        if (empty($reference)) {
            return response()->json(['status' => true]); // nothing to do, not an error
        }

        // provider_reference is our per-attempt reference ({request_number}-N),
        // which is what providers echo back; provider_txn_id is their own id.
        $redeem = RedeemRequests::where('provider_reference', $reference)
            ->orWhere('request_number', $reference)
            ->orWhere('provider_txn_id', $reference)
            ->first();
        if ($redeem != null) {
            return $this->resolveRedeemRequest($redeem, $reference, $provider, $settings);
        }

        // Some providers echo back exactly the reference we sent them
        // ("topup_{id}", from TopUpController::initiateCharge) rather than
        // returning their own internal reference — check both forms.
        $topupQuery = CoinTopUpRequests::where('provider_reference', $reference);
        if (str_starts_with((string) $reference, 'topup_')) {
            $topupQuery->orWhere('id', substr($reference, 6));
        }
        $topup = $topupQuery->first();
        if ($topup != null) {
            return $this->resolveTopUpRequest($topup, $reference, $provider, $settings);
        }

        return response()->json(['status' => true]);
    }

    private function resolveRedeemRequest(RedeemRequests $redeem, string $reference, string $provider, GlobalSettings $settings)
    {
        if ($redeem->status != Constants::withdrawalProcessing) {
            return response()->json(['status' => true]); // already finalized, avoid double-processing
        }

        $gateway = PaymentGatewayFactory::forProvider($provider, $settings);
        if ($gateway == null) {
            return response()->json(['status' => true]);
        }
        // Asks the provider's TRANSFER status (verify() is for collections),
        // keyed by our per-attempt reference. Withdrawals claims the status
        // change with the write itself, so two deliveries landing together
        // can neither complete nor refund a payout twice.
        Withdrawals::applyProviderStatus($redeem, $gateway->verifyPayout($redeem->provider_reference ?: $reference));

        return response()->json(['status' => true]);
    }

    private function resolveTopUpRequest(CoinTopUpRequests $topup, string $reference, string $provider, GlobalSettings $settings)
    {
        // Fast path only — NOT the guard that prevents double-crediting. See
        // the claim below for why reading the status can never be the check.
        // 'failed' rows are deliberately re-verified: a late webhook for a
        // payment we wrongly gave up on must still be able to credit it.
        if ($topup->status === 'completed') {
            return response()->json(['status' => true]);
        }

        $gateway = PaymentGatewayFactory::forProvider($provider, $settings);
        if ($gateway == null) {
            return response()->json(['status' => true]);
        }
        $result = $gateway->verify($reference);

        // Crediting lives in TopUpReconciler, shared with the topups:reconcile
        // sweep that catches payments whose webhook never arrives at all
        // (which is how top-up 1 stranded a real payment). Deliberately one
        // implementation: two copies of "have we already credited this?" is
        // how double-credits get reintroduced. The claim there is a single
        // conditional UPDATE, so of the three deliveries Lenco fires for one
        // payment only one can ever credit.
        TopUpReconciler::apply($topup, $result['status']);

        return response()->json(['status' => true]);
    }
}
