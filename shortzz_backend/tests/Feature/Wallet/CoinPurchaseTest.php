<?php

namespace Tests\Feature\Wallet;

use App\Services\Payments\RevenueCatCoinSync;
use App\Services\Payments\TopUpReconciler;
use App\Models\CoinTopUpRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestCase;

class CoinPurchaseTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['RC_PROJECT_ID' => 'projtest', 'RC_KIT_API_KEY' => 'sk_test'] as $key => $value) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
        config(['services.revenuecat.allow_sandbox' => false]);
        cache()->flush();
        DB::table('tbl_coin_plan')->insert([
            'coin_amount' => 500, 'coin_plan_price' => 5, 'playstore_product_id' => 'coins_500',
            'appstore_product_id' => '', 'status' => 1, 'image' => 'x.png',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['RC_PROJECT_ID', 'RC_KIT_API_KEY'] as $key) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        }
        parent::tearDown();
    }

    private array $purchases = [];
    private bool $faked = false;

    // One fake for the whole test (Laravel uses the first matching stub), fed
    // from $this->purchases so a test can change what RevenueCat reports.
    private function revenueCatHas(array $purchases): void
    {
        $this->purchases = $purchases;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/purchases')) {
                return Http::response(['items' => $this->purchases, 'next_page' => null], 200);
            }
            if (str_contains($request->url(), '/products/prodcoins500')) {
                return Http::response(['id' => 'prodcoins500', 'store_identifier' => 'coins_500'], 200);
            }
            return Http::response([], 404);
        });
    }

    private function purchase(string $orderId, array $overrides = []): array
    {
        return array_merge([
            'id' => 'purch_' . $orderId,
            'product_id' => 'prodcoins500',
            'store' => 'play_store',
            'store_purchase_identifier' => $orderId,
            'environment' => 'production',
            'status' => 'owned',
            'quantity' => 1,
        ], $overrides);
    }

    public function test_a_purchase_is_credited_exactly_once_however_often_it_is_synced(): void
    {
        $buyer = $this->makeUser();
        $token = $this->tokenFor($buyer);
        $this->revenueCatHas([$this->purchase('GPA.1111-0001')]);

        foreach (range(1, 5) as $i) {
            $this->api('misc/buyCoins', ['coin_package_id' => 99, 'purchased_at' => 0], $token)->assertJson(['status' => true]);
        }

        $row = $this->userRow($buyer->id);
        $this->assertSame(500, (int) $row->coin_wallet);
        $this->assertSame('5.0000', $row->coin_wallet_value);
        $this->assertSame(500, (int) $row->coin_purchased_lifetime);
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'purchase_iap')->count());
    }

    public function test_sandbox_test_store_and_refunded_purchases_are_never_credited(): void
    {
        $buyer = $this->makeUser();
        $this->revenueCatHas([
            $this->purchase('GPA.sandbox', ['environment' => 'sandbox']),
            $this->purchase('TEST.store', ['store' => 'test_store']),
            $this->purchase('GPA.refunded', ['status' => 'refunded']),
        ]);

        RevenueCatCoinSync::syncCustomer($buyer->id);

        $this->assertSame(0, (int) $this->userRow($buyer->id)->coin_wallet);
    }

    public function test_quantity_multiplies_coins_and_value(): void
    {
        $buyer = $this->makeUser();
        $this->revenueCatHas([$this->purchase('GPA.qty', ['quantity' => 3])]);

        RevenueCatCoinSync::syncCustomer($buyer->id);

        $this->assertSame(1500, (int) $this->userRow($buyer->id)->coin_wallet);
        $this->assertSame('15.0000', $this->userRow($buyer->id)->coin_wallet_value);
    }

    public function test_a_refund_takes_back_only_what_is_left(): void
    {
        $buyer = $this->makeUser();
        $creator = $this->makeUser();
        $this->revenueCatHas([$this->purchase('GPA.refund-me')]);
        RevenueCatCoinSync::syncCustomer($buyer->id);
        $this->api('misc/sendGift', ['user_id' => $creator->id, 'gift_id' => $this->makeGift(200)->id], $this->tokenFor($buyer))->assertJson(['status' => true]);

        $this->revenueCatHas([$this->purchase('GPA.refund-me', ['status' => 'refunded'])]);
        RevenueCatCoinSync::syncCustomer($buyer->id);
        RevenueCatCoinSync::syncCustomer($buyer->id);

        $this->assertSame(0, (int) $this->userRow($buyer->id)->coin_wallet);
        $refund = DB::table('wallet_transactions')->where('type', 'iap_refund')->get();
        $this->assertCount(1, $refund);
        $this->assertSame(-300, (int) $refund[0]->coins);
        $this->assertStringContainsString('"shortfall_coins":200', $refund[0]->meta);
    }

    public function test_revenuecat_webhook_credits_without_the_app(): void
    {
        $buyer = $this->makeUser();
        $_SERVER['REVENUECAT_WEBHOOK_SECRET'] = 'secret';
        putenv('REVENUECAT_WEBHOOK_SECRET=secret');
        $this->revenueCatHas([$this->purchase('GPA.webhook')]);

        $this->postJson('/webhooks/revenuecat', ['event' => [
            'type' => 'NON_RENEWING_PURCHASE', 'app_user_id' => (string) $buyer->id, 'product_id' => 'coins_500',
        ]], ['Authorization' => 'secret'])->assertOk();

        $this->assertSame(500, (int) $this->userRow($buyer->id)->coin_wallet);
        unset($_SERVER['REVENUECAT_WEBHOOK_SECRET']);
        putenv('REVENUECAT_WEBHOOK_SECRET');
    }

    public function test_web_top_up_credit_carries_its_price_as_value(): void
    {
        $buyer = $this->makeUser();
        $id = DB::table('tbl_coin_topup_request')->insertGetId([
            'user_id' => $buyer->id, 'coin_package_id' => 11, 'coins' => 6000, 'amount' => 50.00,
            'phone' => '260971234567', 'provider' => 'lenco', 'status' => 'processing',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(TopUpReconciler::apply(CoinTopUpRequests::find($id), 'completed'));
        $this->assertFalse(TopUpReconciler::apply(CoinTopUpRequests::find($id), 'completed'));

        $row = $this->userRow($buyer->id);
        $this->assertSame(6000, (int) $row->coin_wallet);
        $this->assertSame('50.0000', $row->coin_wallet_value);
    }

    public function test_registration_bonus_is_free_coins_granted_once(): void
    {
        $this->setting(['registration_bonus_status' => 1, 'registration_bonus_amount' => 100]);

        $response = $this->api('user/registerUser', [
            'identity' => 'newbie-' . uniqid() . '@example.com',
            'password' => 'Secret123!',
            'login_method' => 'email',
            'fullname' => 'New Bie',
            'device' => 0,
            'device_token' => 'x',
        ])->assertJson(['status' => true]);

        $userId = $response->json('data.id');
        $this->assertSame(100, (int) $this->userRow($userId)->coin_wallet);
        $this->assertSame('0.0000', $this->userRow($userId)->coin_wallet_value);
        $this->assertSame(0, (int) $this->userRow($userId)->coin_collected_lifetime);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $userId)->where('type', 'bonus_registration')->count());
    }
}
