<?php

namespace Tests\Support;

use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Base for tests that drive the app API or touch money tables.
//
// The API middleware reads its headers straight from $_SERVER (CheckHeader,
// AuthorizeUser), which Laravel's test client does not populate, so api()
// sets both. GlobalFunction also memoises users per auth token for the life
// of the PHP process — fine per request in production, stale across the many
// requests a test makes — so the memo is cleared before each call.
abstract class ApiTestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $database = DB::connection()->getDatabaseName();
        if ($database !== 'silitibum_test') {
            $this->fail("Refusing to run against '{$database}' — tests only run on silitibum_test.");
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_APIKEY'], $_SERVER['HTTP_AUTHTOKEN']);
        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): object
    {
        $id = DB::table('tbl_users')->insertGetId(array_merge([
            'identity' => 'test-' . Str::random(10) . '@example.com',
            'fullname' => 'Test User',
            'username' => 'test_' . strtolower(Str::random(10)),
            'login_method' => 'email',
            'is_dummy' => 0,
            'is_freez' => 0,
            'coin_wallet' => 0,
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ], $attributes));
        return DB::table('tbl_users')->where('id', $id)->first();
    }

    protected function tokenFor(object $user): string
    {
        $token = Str::random(40);
        DB::table('user_auth_tokens')->insert(['user_id' => $user->id, 'auth_token' => $token]);
        return $token;
    }

    protected function api(string $uri, array $data = [], ?string $token = null)
    {
        $this->forgetAuthMemo();
        $_SERVER['HTTP_APIKEY'] = 'silitibum';
        $headers = ['apikey' => 'silitibum'];
        if ($token !== null) {
            $_SERVER['HTTP_AUTHTOKEN'] = $token;
            $headers['authtoken'] = $token;
        } else {
            unset($_SERVER['HTTP_AUTHTOKEN']);
        }
        return $this->withHeaders($headers)->post('/api/' . ltrim($uri, '/'), $data);
    }

    protected function setting(array $values): void
    {
        DB::table('tbl_settings')->update($values);
        cache()->forget('app_settings_payload');
    }

    protected function settings(): GlobalSettings
    {
        return GlobalSettings::first();
    }

    protected function userRow(int $id): ?object
    {
        return DB::table('tbl_users')->where('id', $id)->first();
    }

    protected function makeGift(int $coinPrice): object
    {
        $id = DB::table('tbl_gifts')->insertGetId(['coin_price' => $coinPrice, 'image' => 'gift.png']);
        return DB::table('tbl_gifts')->where('id', $id)->first();
    }

    /// Coins bought for $price kwacha, through the ledger like a real top-up.
    protected function fundCoins(object $user, int $coins, string $price): void
    {
        \App\Services\Wallet\WalletLedger::creditCoins(
            (int) $user->id, $coins, \App\Services\Wallet\Money::toUnits($price),
            \App\Services\Wallet\WalletLedger::TYPE_PURCHASE_TOPUP, 'test-topup:' . Str::random(12)
        );
    }

    protected function payoutAccount(object $user, array $attributes = []): void
    {
        DB::table('creator_profiles')->insert(array_merge([
            'user_id' => $user->id,
            'legal_name' => 'Beata Jean Mwansa',
            'payout_phone' => '0971234567',
            'payout_operator' => 'airtel',
            'name_match' => 'unverified',
            'adult_confirmed_at' => now()->subDays(10),
            'status' => 'active',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ], $attributes));
    }

    protected function earnings(int $userId): string
    {
        return (string) DB::table('tbl_users')->where('id', $userId)->value('earnings_balance');
    }

    private function forgetAuthMemo(): void
    {
        $property = new \ReflectionProperty(GlobalFunction::class, 'userAuthTokenCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
