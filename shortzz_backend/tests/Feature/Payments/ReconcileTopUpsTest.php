<?php

namespace Tests\Feature\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ApiTestCase;

class ReconcileTopUpsTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting(['lenco_api_key' => 'test-key', 'active_collection_provider' => 'lenco']);
    }

    private function topup(object $user, string $status, int $hoursAgo, int $coins = 500, float $amount = 5.00): int
    {
        return DB::table('tbl_coin_topup_request')->insertGetId([
            'user_id' => $user->id,
            'coin_package_id' => 9,
            'coins' => $coins,
            'amount' => $amount,
            'phone' => '260971234567',
            'provider' => 'lenco',
            'provider_reference' => '2620809422',
            'status' => $status,
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function lencoSays(array $byReference): void
    {
        Http::fake(function ($request) use ($byReference) {
            foreach ($byReference as $reference => $response) {
                if (str_ends_with($request->url(), '/collections/status/' . $reference)) {
                    return $response;
                }
            }
            return Http::response(['status' => false, 'message' => 'Collection details was not found'], 400);
        });
    }

    public function test_not_found_inside_the_expiry_window_stays_open(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'processing', 3);
        $this->lencoSays([]);

        $this->artisan('topups:reconcile')->assertExitCode(0);

        $this->assertSame('processing', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
    }

    public function test_not_found_after_the_expiry_window_fails(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'processing', 30);
        $this->lencoSays([]);

        $this->artisan('topups:reconcile')->assertExitCode(0);

        $this->assertSame('failed', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) $this->userRow($user->id)->coin_wallet);
    }

    public function test_provider_outage_never_fails_a_top_up(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'processing', 30);
        Http::fake(['*' => Http::response(['message' => 'Bad gateway'], 502)]);

        $this->artisan('topups:reconcile')->assertExitCode(0);

        $this->assertSame('processing', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
    }

    public function test_success_under_our_reference_credits_once(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'processing', 1);
        $this->lencoSays(["topup_{$id}" => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

        $this->artisan('topups:reconcile')->assertExitCode(0);
        $this->artisan('topups:reconcile')->assertExitCode(0);

        $this->assertSame('completed', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
        $this->assertSame(500, (int) $this->userRow($user->id)->coin_wallet);
    }

    public function test_buried_payment_is_rescued_only_with_include_failed(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'failed', 48);
        $this->lencoSays(["topup_{$id}" => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

        $this->artisan('topups:reconcile', ['--days' => 90])->assertExitCode(0);
        $this->assertSame('failed', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));

        $this->artisan('topups:reconcile', ['--days' => 90, '--include-failed' => true, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, (int) $this->userRow($user->id)->coin_wallet);

        $this->artisan('topups:reconcile', ['--days' => 90, '--include-failed' => true])->assertExitCode(0);
        $this->artisan('topups:reconcile', ['--days' => 90, '--include-failed' => true])->assertExitCode(0);

        $this->assertSame('completed', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
        $this->assertSame(500, (int) $this->userRow($user->id)->coin_wallet);
    }

    public function test_failed_row_that_is_still_unpaid_is_left_alone(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'failed', 48);
        $this->lencoSays(["topup_{$id}" => Http::response(['status' => true, 'data' => ['status' => 'failed']], 200)]);

        $this->artisan('topups:reconcile', ['--days' => 90, '--include-failed' => true])->assertExitCode(0);

        $this->assertSame('failed', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
        $this->assertSame(0, (int) $this->userRow($user->id)->coin_wallet);
    }

    public function test_late_webhook_rescues_a_failed_row(): void
    {
        $user = $this->makeUser();
        $id = $this->topup($user, 'failed', 5);
        $this->lencoSays(["topup_{$id}" => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

        $body = json_encode(['event' => 'collection.successful', 'data' => ['reference' => "topup_{$id}"]]);
        $signature = hash_hmac('sha512', $body, hash('sha256', 'test-key'));
        $this->call('POST', '/webhooks/lenco', [], [], [], ['HTTP_X_LENCO_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body)
            ->assertOk();

        $this->assertSame('completed', DB::table('tbl_coin_topup_request')->where('id', $id)->value('status'));
        $this->assertSame(500, (int) $this->userRow($user->id)->coin_wallet);
    }
}
