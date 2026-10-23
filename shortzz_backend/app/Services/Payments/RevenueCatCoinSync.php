<?php

namespace App\Services\Payments;

use App\Models\CoinPackages;
use App\Services\Wallet\Money;
use App\Services\Wallet\WalletLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Credits in-app coin purchases from RevenueCat, exactly once each.
//
// The old buyCoins credited "the newest purchase made in the last 30 seconds"
// and remembered nothing, so calling it N times inside that window credited N
// times — and a purchase confirmed after 30 s was paid for and never credited.
// It also accepted sandbox/test-store purchases and refunded ones.
//
// Now every purchase is keyed by its store order id (iap:{id}) in the ledger,
// so this can run as often as anyone likes — from the app right after a
// purchase, and from RevenueCat's webhook as the guaranteed path — and only
// ever credits what has not been credited. A purchase RevenueCat later reports
// as refunded takes its coins back (iap-refund:{id}).
final class RevenueCatCoinSync
{
    private const STORES = ['play_store', 'app_store'];

    /// ['credited' => purchases newly credited, 'coins' => coins added,
    ///  'clawed_back' => refunds taken back], or null when RevenueCat could
    /// not be asked (the next sync or webhook will catch up).
    public static function syncCustomer(int $userId): ?array
    {
        $projectId = env('RC_PROJECT_ID');
        $apiKey = env('RC_KIT_API_KEY');
        if (empty($projectId) || empty($apiKey)) {
            Log::error('RevenueCatCoinSync: RC_PROJECT_ID / RC_KIT_API_KEY not configured');
            return null;
        }
        $client = Http::withToken($apiKey)->baseUrl('https://api.revenuecat.com/v2')->timeout(20);

        $items = [];
        $path = "/projects/{$projectId}/customers/{$userId}/purchases?limit=100";
        for ($page = 0; $page < 20 && $path !== null; $page++) {
            try {
                $response = $client->get($path);
            } catch (\Throwable $e) {
                Log::error('RevenueCatCoinSync: purchases request failed: ' . $e->getMessage());
                return null;
            }
            if (!$response->successful()) {
                Log::error('RevenueCatCoinSync: purchases request returned ' . $response->status());
                return null;
            }
            $items = array_merge($items, $response->json('items') ?? []);
            $next = $response->json('next_page');
            $path = $next ? preg_replace('#^/v2#', '', (string) $next) : null;
        }

        $allowSandbox = (bool) config('services.revenuecat.allow_sandbox', false);
        $result = ['credited' => 0, 'coins' => 0, 'clawed_back' => 0];
        foreach ($items as $item) {
            $orderId = $item['store_purchase_identifier'] ?? null;
            $store = $item['store'] ?? null;
            if (empty($orderId) || !in_array($store, self::STORES, true)) {
                continue;
            }
            if (($item['environment'] ?? 'production') !== 'production' && !$allowSandbox) {
                continue;
            }
            $package = self::packageFor($client, (string) $projectId, $item['product_id'] ?? null, $store);
            if ($package === null) {
                continue; // not a coin pack (the verified-badge subscription, say)
            }
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $coins = (int) $package->coin_amount * $quantity;

            if (($item['status'] ?? 'owned') === 'refunded') {
                // Only what was credited can be taken back; the credit's time
                // bounds which later gifts the refunded money could have paid for.
                $credit = DB::table('wallet_transactions')->where('idempotency_key', 'iap:' . $orderId)->first(['created_at']);
                if ($credit !== null && WalletLedger::refundPurchase(
                    $userId,
                    $coins,
                    Money::toUnits($package->coin_plan_price) * $quantity,
                    'iap-refund:' . $orderId,
                    (string) $credit->created_at,
                    ['order_id' => $orderId]
                )) {
                    $result['clawed_back']++;
                    Log::warning("RevenueCatCoinSync: refunded purchase {$orderId} taken back from user {$userId}");
                }
                continue;
            }
            if (($item['status'] ?? 'owned') !== 'owned') {
                continue;
            }

            $credited = WalletLedger::creditCoins(
                $userId,
                $coins,
                Money::toUnits($package->coin_plan_price) * $quantity,
                WalletLedger::TYPE_PURCHASE_IAP,
                'iap:' . $orderId,
                [],
                ['order_id' => $orderId, 'store' => $store, 'coin_package_id' => $package->id, 'environment' => $item['environment'] ?? null]
            );
            if ($credited) {
                $result['credited']++;
                $result['coins'] += $coins;
            }
        }
        return $result;
    }

    /// RevenueCat's product id -> the store's product id -> our coin package.
    private static function packageFor($client, string $projectId, ?string $productId, string $store): ?CoinPackages
    {
        if (empty($productId)) {
            return null;
        }
        $storeIdentifier = Cache::remember('rc_product_store_identifier:' . $productId, now()->addDay(), function () use ($client, $projectId, $productId) {
            try {
                $response = $client->get("/projects/{$projectId}/products/{$productId}");
            } catch (\Throwable $e) {
                return null;
            }
            return $response->successful() ? (string) ($response->json('store_identifier') ?? '') : null;
        });
        if (empty($storeIdentifier)) {
            return null;
        }
        $column = $store === 'play_store' ? 'playstore_product_id' : 'appstore_product_id';
        return CoinPackages::where($column, $storeIdentifier)->first();
    }
}
