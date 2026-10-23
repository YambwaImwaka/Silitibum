<?php

namespace App\Http\Controllers;

use App\Models\CoinPackages;
use App\Models\CoinTopUpRequests;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Public web page for buying coins via mobile money — deliberately kept
// outside the mobile app. Apple/Google require in-app virtual-currency
// purchases to go through their own IAP systems (RevenueCat, see
// WalletController::buyCoins); routing mobile money through the app itself
// risks the app being rejected/pulled. This page is the same account and
// the same coin_wallet, just a different entry point — the same pattern
// TikTok itself uses in some markets. Reached at GET /topup, guarded by
// CheckAppUserLogin (see AppUserLoginController for the login half).
class TopUpController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->attributes->get('appUser');
        $settings = GlobalSettings::first();
        $packages = CoinPackages::where('status', 1)->get();
        $provider = $settings->active_collection_provider;

        return view('topup', [
            'user' => $user,
            'settings' => $settings,
            'packages' => $packages,
            'providerAvailable' => !empty($provider) && PaymentGatewayFactory::forProvider($provider, $settings) != null,
        ]);
    }

    public function initiateCharge(Request $request)
    {
        $user = $request->attributes->get('appUser');
        $request->validate([
            'coin_package_id' => 'required|exists:tbl_coin_plan,id',
            'phone' => 'required|regex:/^\+?[0-9]{8,15}$/',
        ]);

        // Same country-code problem the login half has: the regex above happily
        // accepts a local '0977123456', but every gateway wants the full
        // international MSISDN, so normalize before it reaches one — and store
        // the normalized value, since that's what the webhook reconciles against.
        $phone = GlobalFunction::canonicalMsisdn($request->phone);
        if ($phone === null) {
            return response()->json(['status' => false, 'message' => 'Please enter a valid mobile money number.']);
        }

        $package = CoinPackages::find($request->coin_package_id);
        $settings = GlobalSettings::first();
        $provider = $settings->active_collection_provider;
        $gateway = PaymentGatewayFactory::forProvider($provider, $settings);
        if ($gateway == null) {
            return response()->json(['status' => false, 'message' => 'Coin top-up is temporarily unavailable.']);
        }

        $topup = new CoinTopUpRequests();
        $topup->user_id = $user->id;
        $topup->coin_package_id = $package->id;
        $topup->coins = $package->coin_amount;
        $topup->amount = $package->coin_plan_price;
        $topup->phone = $phone;
        $topup->provider = $provider;
        $topup->status = 'pending';
        $topup->save();

        try {
            $result = $gateway->charge($phone, (float) $package->coin_plan_price, $settings->currency ?? 'USD', 'topup_' . $topup->id);
        } catch (\Throwable $e) {
            // Same rule as GatewayResult: an exception says nothing about
            // whether the push reached the customer's phone.
            Log::error('TopUpController::initiateCharge threw: ' . $e->getMessage());
            $result = GatewayResult::unknown(null, 'gateway_exception');
        }

        $topup->provider_reference = $result['provider_reference'] ?? null;
        // 'processing' even on an immediate 'completed' from the gateway —
        // the wallet is only ever credited by the webhook below, never by
        // this synchronous response, so the row stays non-terminal until
        // that actually happens.
        $topup->status = $result['status'] === 'failed' ? 'failed' : 'processing';
        $topup->save();

        if ($result['status'] === 'failed') {
            return response()->json(['status' => false, 'message' => 'The charge could not be started. Please try again.']);
        }

        // The wallet is credited only by the webhook (PaymentWebhookController),
        // never here — never trust the browser page/gateway's synchronous
        // response alone for something that moves money, same principle
        // WalletController::buyCoins uses for RevenueCat purchases.
        //
        // An unconfirmed start (timeout, provider error) stays open too: the
        // topups:reconcile sweep asks the provider by 'topup_{id}' and either
        // credits it or expires it, so the page keeps polling either way.
        return response()->json([
            'status' => true,
            'message' => !empty($result['unconfirmed'])
                ? 'We could not confirm the request yet. If a prompt appears on your phone, approve it and your coins will arrive automatically.'
                : 'Check your phone to approve the payment.',
            'data' => ['topup_id' => $topup->id],
        ]);
    }

    // Polled by the page while waiting for the webhook to land, so the
    // browser can show "done" without the user having to refresh manually.
    public function checkStatus(Request $request)
    {
        $user = $request->attributes->get('appUser');
        $topup = CoinTopUpRequests::where('id', $request->topup_id)->where('user_id', $user->id)->first();
        if ($topup == null) {
            return response()->json(['status' => false, 'message' => 'not found']);
        }
        return response()->json(['status' => true, 'data' => ['status' => $topup->status]]);
    }
}
