<?php

namespace App\Http\Controllers;

use App\Models\CoinPackages;
use App\Models\Users;
use App\Services\Payments\RevenueCatCoinSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Authoritative source for Users.is_verify. The client is never trusted to
// set this itself (see UserController::updateUserDetails, which deliberately
// excludes is_verify from its updatable fields) — RevenueCat is the only
// party that knows whether a subscription is actually active, so it's the
// only party allowed to flip the flag, in both directions.
//
// Configure in the RevenueCat dashboard: Project Settings -> Integrations ->
// Webhooks. Set the URL to POST /webhooks/revenuecat and the "Authorization
// header value" to match REVENUECAT_WEBHOOK_SECRET below — RevenueCat sends
// that exact string back as the Authorization header on every call, which is
// the whole authenticity check (a static shared secret, not HMAC signing).
class RevenueCatWebhookController extends Controller
{
    private const GRANTS = ['INITIAL_PURCHASE', 'RENEWAL', 'UNCANCELLATION', 'PRODUCT_CHANGE'];

    // Only EXPIRATION ends access. CANCELLATION fires the moment a subscriber
    // turns auto-renew off — they have still paid through the period — and
    // BILLING_ISSUE opens a grace period that EXPIRATION closes if it lapses.
    // Both used to strip the badge immediately. A refund (CANCELLATION with
    // cancel_reason CUSTOMER_SUPPORT) is the one cancellation that ends
    // access now.
    private const REVOKES = ['EXPIRATION'];

    public function handle(Request $request)
    {
        $expected = env('REVENUECAT_WEBHOOK_SECRET');
        $given = $request->header('Authorization');
        if (empty($expected) || $given !== $expected) {
            Log::error('RevenueCat webhook: bad or missing Authorization header');
            return response()->json(['status' => false], 401);
        }

        $event = $request->input('event', []);
        $type = $event['type'] ?? null;
        $appUserId = $event['app_user_id'] ?? null;

        if (!$type || !$appUserId || !ctype_digit((string) $appUserId)) {
            // Anonymous/alias events before login() associates a real user id
            // land here too (app_user_id would be a RevenueCat-generated
            // UUID, not our numeric id) — nothing to do, not an error.
            return response()->json(['status' => true]);
        }

        $user = Users::find((int) $appUserId);
        if ($user == null) {
            Log::error("RevenueCat webhook: no user for app_user_id $appUserId");
            return response()->json(['status' => true]);
        }

        // Coin packs are consumables, not the badge subscription: buying or
        // refunding one must never touch is_verify (a coin refund arrives as
        // a CANCELLATION too). Instead the purchase list is synced — the
        // guaranteed path that credits a purchase even when the app never got
        // to call buyCoins, and takes back a refunded one. Idempotent, so the
        // app's own call and every redelivery are harmless.
        if ($type === 'NON_RENEWING_PURCHASE' || $this->isCoinProduct($event['product_id'] ?? null)) {
            RevenueCatCoinSync::syncCustomer($user->id);
            return response()->json(['status' => true]);
        }

        $isRefund = $type === 'CANCELLATION' && ($event['cancel_reason'] ?? null) === 'CUSTOMER_SUPPORT';
        if (in_array($type, self::GRANTS)) {
            $user->is_verify = 1;
            $user->save();
        } elseif (in_array($type, self::REVOKES) || $isRefund) {
            $user->is_verify = 0;
            $user->save();
        }
        // Other event types (TRANSFER, NON_RENEWING_PURCHASE, etc.) are
        // intentionally ignored — they don't change verified-badge state.

        return response()->json(['status' => true]);
    }

    private function isCoinProduct(?string $productId): bool
    {
        if (empty($productId)) {
            return false;
        }
        return CoinPackages::where('playstore_product_id', $productId)
            ->orWhere('appstore_product_id', $productId)
            ->exists();
    }
}
