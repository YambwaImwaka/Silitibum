<?php

namespace Tests\Feature;

use App\Services\Verification\BlueTick;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApiTestCase;

// The admin gives anyone the blue tick from the panel, and every page that
// shows it still renders.
class AdminBlueTickTest extends ApiTestCase
{
    private function asAdmin(int $userType = 1): static
    {
        $username = 'test_admin_' . Str::random(8);
        DB::table('tbl_admin')->insert([
            'admin_name' => 'Test Admin',
            'admin_username' => $username,
            'admin_password' => encrypt('secret'),
            'admin_profile' => '',
            'user_type' => $userType,
        ]);
        return $this->withSession(['username' => $username, 'userpassword' => 'secret', 'user_type' => $userType]);
    }

    public function test_admin_gives_and_removes_the_tick_of_a_real_account(): void
    {
        $user = $this->makeUser(['is_dummy' => 0]);

        $this->asAdmin()->post('/changeUserVerifyStatus', ['user_id' => $user->id, 'is_verify' => 1])
            ->assertJsonPath('status', true);
        $row = $this->userRow($user->id);
        $this->assertSame(1, (int) $row->is_verify);
        $this->assertSame('admin', $row->verification_source);

        $this->asAdmin()->post('/changeUserVerifyStatus', ['user_id' => $user->id, 'is_verify' => 0])
            ->assertJsonPath('status', true);
        $this->assertSame(0, (int) $this->userRow($user->id)->is_verify);
    }

    public function test_a_tester_admin_cannot_hand_out_ticks(): void
    {
        $user = $this->makeUser();

        $this->asAdmin(0)->post('/changeUserVerifyStatus', ['user_id' => $user->id, 'is_verify' => 1])
            ->assertStatus(403);
        $this->assertSame(0, (int) $this->userRow($user->id)->is_verify);
    }

    public function test_the_admin_pages_render_with_the_tick_controls(): void
    {
        $paid = $this->makeUser(['is_verify' => 1, 'verification_source' => 'paid', 'verified_until' => now()->addDays(10)]);
        $admin = $this->asAdmin();

        $admin->get('/viewUserDetails/' . $paid->id)->assertOk()->assertSee('switchVerifyStatus', false)->assertSee('Paid');
        $this->asAdmin()->get('/setting')->assertOk()->assertSee('verification_price', false);
        $this->asAdmin()->get('/users')->assertOk()->assertSee('Blue tick');

        $list = $this->asAdmin()->post('/listAllUsers', ['draw' => 1, 'start' => 0, 'length' => 10]);
        $list->assertOk();
        $this->assertStringContainsString('verifyUser', json_encode($list->json('data')));
    }

    public function test_the_verify_page_shows_the_price_and_status(): void
    {
        $user = $this->makeUser();
        $this->setting(['verification_price' => '75.00', 'verification_days' => 30,
            'verification_enabled' => 1, 'lenco_api_key' => 'test-key', 'active_collection_provider' => 'lenco']);

        $this->withSession(['app_user_id' => $user->id])->get('/verify')
            ->assertOk()->assertSee('75.00')->assertSee('for 30 days');

        BlueTick::grantByAdmin((int) $user->id);
        $this->withSession(['app_user_id' => $user->id])->get('/verify')
            ->assertOk()->assertSee('never expires');
    }
}
