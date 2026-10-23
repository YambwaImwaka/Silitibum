<?php

namespace Tests\Feature\Payments;

use App\Http\Controllers\AppUserLoginController;
use App\Models\CoinTopUpRequests;
use App\Services\Payments\TopUpReconciler;
use App\Services\Verification\BlueTick;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestCase;

// The blue tick: given free by the admin, or bought with mobile money at
// /verify — and each source can only end its own kind.
class BlueTickTest extends ApiTestCase
{
    private const RC_SECRET = 'rc-test-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting([
            'lenco_api_key' => 'test-key',
            'active_collection_provider' => 'lenco',
            'verification_enabled' => 1,
            'verification_price' => '50.00',
            'verification_days' => 30,
        ]);
        $_SERVER['REVENUECAT_WEBHOOK_SECRET'] = self::RC_SECRET;
        $_ENV['REVENUECAT_WEBHOOK_SECRET'] = self::RC_SECRET;
        putenv('REVENUECAT_WEBHOOK_SECRET=' . self::RC_SECRET);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REVENUECAT_WEBHOOK_SECRET'], $_ENV['REVENUECAT_WEBHOOK_SECRET']);
        putenv('REVENUECAT_WEBHOOK_SECRET');
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paying_on_the_web_turns_the_tick_on_only_once_the_provider_confirms(): void
    {
        $user = $this->makeUser();
        Http::fake(['*/collections/mobile-money' => Http::response(
            ['status' => true, 'data' => ['status' => 'pay-offline', 'lencoReference' => 'L-1']], 200)]);

        $response = $this->withSession(['app_user_id' => $user->id])
            ->post('/verify/charge', ['phone' => '0971234567']);

        $response->assertJsonPath('status', true);
        $row = DB::table('tbl_coin_topup_request')->where('id', $response->json('data.topup_id'))->first();
        $this->assertSame('verification', $row->purpose);
        $this->assertSame('50.00', (string) $row->amount);
        $this->assertNull($row->coin_package_id);
        $this->assertSame(0, (int) $row->coins);
        $this->assertSame(0, (int) $this->userRow($user->id)->is_verify, 'nothing before the payment is confirmed');

        $this->assertTrue(TopUpReconciler::apply(CoinTopUpRequests::find($row->id), 'completed'));

        $after = $this->userRow($user->id);
        $this->assertSame(1, (int) $after->is_verify);
        $this->assertSame('paid', $after->verification_source);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, Carbon::parse($after->verified_until)->timestamp, 5);
        // No coins for a blue tick.
        $this->assertSame(0, (int) $after->coin_wallet);
    }

    public function test_a_confirmation_delivered_twice_adds_one_period(): void
    {
        $user = $this->makeUser();
        $topup = $this->verificationPayment($user);

        $this->assertTrue(TopUpReconciler::apply($topup, 'completed'));
        $firstUntil = $this->userRow($user->id)->verified_until;
        $this->assertFalse(TopUpReconciler::apply(CoinTopUpRequests::find($topup->id), 'completed'));

        $this->assertSame($firstUntil, $this->userRow($user->id)->verified_until);
    }

    public function test_paying_again_extends_from_the_end_of_the_current_period(): void
    {
        $user = $this->makeUser();
        TopUpReconciler::apply($this->verificationPayment($user), 'completed');
        $firstUntil = Carbon::parse($this->userRow($user->id)->verified_until);

        TopUpReconciler::apply($this->verificationPayment($user), 'completed');

        $this->assertEqualsWithDelta($firstUntil->copy()->addDays(30)->timestamp,
            Carbon::parse($this->userRow($user->id)->verified_until)->timestamp, 5);
    }

    public function test_an_admin_given_tick_is_never_touched_by_purchases_lapses_or_expiry(): void
    {
        $user = $this->makeUser();
        BlueTick::grantByAdmin((int) $user->id);

        $this->withSession(['app_user_id' => $user->id])
            ->post('/verify/charge', ['phone' => '0971234567'])
            ->assertJsonPath('status', false);
        $this->postJson('/webhooks/revenuecat', ['event' => [
            'type' => 'EXPIRATION', 'app_user_id' => (string) $user->id, 'product_id' => 'silitibum_verified_monthly',
        ]], ['Authorization' => self::RC_SECRET])->assertOk();
        $this->artisan('verification:expire')->assertExitCode(0);

        $row = $this->userRow($user->id);
        $this->assertSame(1, (int) $row->is_verify);
        $this->assertSame('admin', $row->verification_source);
        $this->assertNull($row->verified_until);
    }

    public function test_admin_can_take_any_tick_away(): void
    {
        $user = $this->makeUser();
        TopUpReconciler::apply($this->verificationPayment($user), 'completed');

        BlueTick::revokeByAdmin((int) $user->id);

        $row = $this->userRow($user->id);
        $this->assertSame(0, (int) $row->is_verify);
        $this->assertNull($row->verification_source);
    }

    public function test_paid_ticks_expire_when_their_period_ends(): void
    {
        $lapsed = $this->makeUser(['is_verify' => 1, 'verification_source' => 'paid', 'verified_until' => now()->subMinute()]);
        $current = $this->makeUser(['is_verify' => 1, 'verification_source' => 'paid', 'verified_until' => now()->addDays(3)]);
        $forever = $this->makeUser(['is_verify' => 1, 'verification_source' => 'paid', 'verified_until' => null]);

        $this->artisan('verification:expire')->assertExitCode(0);

        $this->assertSame(0, (int) $this->userRow($lapsed->id)->is_verify);
        $this->assertNull($this->userRow($lapsed->id)->verification_source);
        $this->assertSame(1, (int) $this->userRow($current->id)->is_verify);
        $this->assertSame(1, (int) $this->userRow($forever->id)->is_verify);
    }

    public function test_nothing_is_sold_while_sales_are_off(): void
    {
        $this->setting(['verification_enabled' => 0]);
        $user = $this->makeUser();

        $this->withSession(['app_user_id' => $user->id])
            ->post('/verify/charge', ['phone' => '0971234567'])
            ->assertJsonPath('status', false);
        $this->assertSame(0, DB::table('tbl_coin_topup_request')->where('user_id', $user->id)->count());
    }

    public function test_the_apps_sign_in_link_works_once(): void
    {
        $user = $this->makeUser(['password' => null]); // a Google sign-in account

        $response = $this->api('misc/createWebLoginLink', ['next' => 'verify'], $this->tokenFor($user));
        $response->assertJsonPath('status', true);
        $path = parse_url($response->json('data.url'), PHP_URL_PATH) . '?' . parse_url($response->json('data.url'), PHP_URL_QUERY);

        $this->get($path)->assertRedirect(route('verify.show'));
        $this->assertSame($user->id, session('app_user_id'));

        $this->flushSession();
        $this->get($path)->assertRedirect(route('topup.login'));
        $this->assertNull(session('app_user_id'));
    }

    public function test_a_link_is_only_made_for_a_signed_in_user(): void
    {
        $url = AppUserLoginController::createLink(123456789, 'verify');
        $this->get(parse_url($url, PHP_URL_PATH) . '?t=wrong-token&next=verify')->assertRedirect(route('topup.login'));
    }

    private function verificationPayment(object $user): CoinTopUpRequests
    {
        $id = DB::table('tbl_coin_topup_request')->insertGetId([
            'user_id' => $user->id,
            'purpose' => 'verification',
            'coin_package_id' => null,
            'coins' => 0,
            'amount' => '50.00',
            'phone' => '260971234567',
            'provider' => 'lenco',
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return CoinTopUpRequests::find($id);
    }
}
