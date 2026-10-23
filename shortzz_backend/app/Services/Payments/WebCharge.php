<?php

namespace App\Services\Payments;

use App\Models\CoinTopUpRequests;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use Illuminate\Support\Facades\Log;

// Starts a mobile-money charge from one of the web payment pages: coins at
// /topup, the blue tick at /verify. Both are rows of tbl_coin_topup_request
// (told apart by `purpose`) charged under the reference topup_{id}, so the
// provider webhook and the topups:reconcile sweep settle them the same way
// through TopUpReconciler — and only ever on the provider's confirmation,
// never on this synchronous response.
class WebCharge
{
    /** @param string $outcome what happens once paid, for the waiting message */
    public static function start($user, string $purpose, string $amount, string $rawPhone,
                                 ?int $packageId, int $coins, string $outcome): array
    {
        // Every gateway wants the full international MSISDN; the page accepts
        // the local '0977…' form too. The normalized value is stored, since
        // that is what the webhook reconciles against.
        $phone = GlobalFunction::canonicalMsisdn($rawPhone);
        if ($phone === null) {
            return ['status' => false, 'message' => 'Please enter a valid mobile money number.'];
        }

        $settings = GlobalSettings::first();
        $provider = $settings->active_collection_provider;
        $gateway = PaymentGatewayFactory::forProvider($provider, $settings);
        if ($gateway == null) {
            return ['status' => false, 'message' => 'Mobile money payments are temporarily unavailable.'];
        }

        $topup = new CoinTopUpRequests();
        $topup->user_id = $user->id;
        $topup->purpose = $purpose;
        $topup->coin_package_id = $packageId;
        $topup->coins = $coins;
        $topup->amount = $amount;
        $topup->phone = $phone;
        $topup->provider = $provider;
        $topup->status = 'pending';
        $topup->save();

        try {
            $result = $gateway->charge($phone, (float) $amount, $settings->currency ?? 'USD', 'topup_' . $topup->id);
        } catch (\Throwable $e) {
            // Same rule as GatewayResult: an exception says nothing about
            // whether the push reached the customer's phone.
            Log::error('WebCharge::start threw: ' . $e->getMessage());
            $result = GatewayResult::unknown(null, 'gateway_exception');
        }

        $topup->provider_reference = $result['provider_reference'] ?? null;
        // 'processing' even on an immediate 'completed' from the gateway: the
        // row only becomes final through TopUpReconciler.
        $topup->status = $result['status'] === 'failed' ? 'failed' : 'processing';
        $topup->save();

        if ($result['status'] === 'failed') {
            return ['status' => false, 'message' => 'The charge could not be started. Please try again.'];
        }

        // An unconfirmed start (timeout, provider error) stays open too: the
        // topups:reconcile sweep asks the provider by topup_{id} and settles
        // it, so the page keeps polling either way.
        return [
            'status' => true,
            'message' => !empty($result['unconfirmed'])
                ? "We could not confirm the request yet. If a prompt appears on your phone, approve it and {$outcome}."
                : 'Check your phone to approve the payment.',
            'data' => ['topup_id' => $topup->id],
        ];
    }
}
