<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestCase;

class RevenueCatWebhookTest extends ApiTestCase
{
    private const SECRET = 'rc-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['REVENUECAT_WEBHOOK_SECRET'] = self::SECRET;
        $_ENV['REVENUECAT_WEBHOOK_SECRET'] = self::SECRET;
        putenv('REVENUECAT_WEBHOOK_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REVENUECAT_WEBHOOK_SECRET'], $_ENV['REVENUECAT_WEBHOOK_SECRET']);
        putenv('REVENUECAT_WEBHOOK_SECRET');
        parent::tearDown();
    }

    private function event(object $user, string $type, array $extra = [])
    {
        return $this->postJson('/webhooks/revenuecat', ['event' => array_merge([
            'type' => $type,
            'app_user_id' => (string) $user->id,
            'product_id' => 'silitibum_verified_monthly',
        ], $extra)], ['Authorization' => self::SECRET]);
    }

    public function test_turning_auto_renew_off_keeps_the_badge(): void
    {
        $user = $this->makeUser(['is_verify' => 1]);

        $this->event($user, 'CANCELLATION', ['cancel_reason' => 'UNSUBSCRIBE'])->assertOk();
        $this->event($user, 'BILLING_ISSUE')->assertOk();

        $this->assertSame(1, (int) $this->userRow($user->id)->is_verify);
    }

    public function test_expiration_and_refund_remove_the_badge(): void
    {
        $expired = $this->makeUser(['is_verify' => 1]);
        $refunded = $this->makeUser(['is_verify' => 1]);

        $this->event($expired, 'EXPIRATION')->assertOk();
        $this->event($refunded, 'CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT'])->assertOk();

        $this->assertSame(0, (int) $this->userRow($expired->id)->is_verify);
        $this->assertSame(0, (int) $this->userRow($refunded->id)->is_verify);
    }

    public function test_purchase_grants_the_badge(): void
    {
        $user = $this->makeUser(['is_verify' => 0]);

        $this->event($user, 'INITIAL_PURCHASE')->assertOk();

        $this->assertSame(1, (int) $this->userRow($user->id)->is_verify);
    }

    public function test_coin_pack_refund_never_touches_the_badge(): void
    {
        DB::table('tbl_coin_plan')->insert(['coin_amount' => 500, 'coin_plan_price' => 5, 'playstore_product_id' => 'coins_500', 'appstore_product_id' => '', 'status' => 1, 'image' => 'x.png']);
        $user = $this->makeUser(['is_verify' => 1]);

        $this->event($user, 'CANCELLATION', ['cancel_reason' => 'CUSTOMER_SUPPORT', 'product_id' => 'coins_500'])->assertOk();

        $this->assertSame(1, (int) $this->userRow($user->id)->is_verify);
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $user = $this->makeUser(['is_verify' => 1]);

        $this->postJson('/webhooks/revenuecat', ['event' => ['type' => 'EXPIRATION', 'app_user_id' => (string) $user->id]], ['Authorization' => 'nope'])
            ->assertStatus(401);

        $this->assertSame(1, (int) $this->userRow($user->id)->is_verify);
    }
}
