<?php

namespace App\Http\Controllers;

use App\Models\GlobalSettings;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Payments\WebCharge;
use App\Services\Verification\BlueTick;
use Illuminate\Http\Request;

// The blue tick, bought with mobile money (Lenco) on the web — the same page
// family, sign-in and payment rails as the coin top-up (see TopUpController
// for why payments live on the web rather than in the app). The tick is only
// switched on once the provider confirms the payment, by TopUpReconciler.
class VerificationController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->attributes->get('appUser');
        $settings = GlobalSettings::first();
        $provider = $settings->active_collection_provider;

        return view('verify', [
            'user' => $user,
            'status' => BlueTick::status($user),
            'providerAvailable' => !empty($provider) && PaymentGatewayFactory::forProvider($provider, $settings) != null,
        ]);
    }

    public function charge(Request $request)
    {
        $user = $request->attributes->get('appUser');
        $request->validate([
            'phone' => 'required|regex:/^\+?[0-9]{8,15}$/',
        ]);

        $status = BlueTick::status($user);
        if (!$status['enabled'] || (float) $status['price'] <= 0) {
            return response()->json(['status' => false, 'message' => 'Blue ticks are not on sale right now.']);
        }
        if ($status['verified'] && $status['source'] === BlueTick::SOURCE_ADMIN) {
            return response()->json(['status' => false, 'message' => 'Your blue tick was given by Silitibum and never expires.']);
        }
        if ($status['verified'] && $status['source'] === BlueTick::SOURCE_PAID && $status['verified_until'] === null) {
            return response()->json(['status' => false, 'message' => 'Your blue tick never expires.']);
        }

        return response()->json(WebCharge::start(
            $user, 'verification', $status['price'], (string) $request->phone,
            null, 0, 'your blue tick will switch on automatically'
        ));
    }
}
