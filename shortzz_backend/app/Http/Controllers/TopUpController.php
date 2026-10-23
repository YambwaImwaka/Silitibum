<?php

namespace App\Http\Controllers;

use App\Models\CoinPackages;
use App\Models\CoinTopUpRequests;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Payments\WebCharge;
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

        // The wallet is credited only by the webhook or the topups:reconcile
        // sweep (TopUpReconciler), never by this response — the same
        // principle WalletController::buyCoins uses for RevenueCat purchases.
        $package = CoinPackages::find($request->coin_package_id);
        return response()->json(WebCharge::start(
            $user, 'coins', (string) $package->coin_plan_price, (string) $request->phone,
            (int) $package->id, (int) $package->coin_amount, 'your coins will arrive automatically'
        ));
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
