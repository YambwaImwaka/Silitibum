<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

// The site root is the public landing page; the admin panel signs in at
// /admin, and everything behind it sends signed-out visitors there.
class LandingPageTest extends ApiTestCase
{
    public function test_the_root_is_the_public_landing_page(): void
    {
        $this->setting(['play_store_download_link' => 'https://play.google.com/store/apps/details?id=com.silitibum.app']);

        $this->get('/')->assertOk()
            ->assertSee('https://play.google.com/store/apps/details?id=com.silitibum.app', false)
            ->assertSee(route('privacy_policy'), false)
            ->assertDontSee('loginForm', false);
    }

    public function test_the_landing_page_is_silitibums_not_the_templates(): void
    {
        $this->setting(['app_name' => 'Silitibum']);

        $this->get('/')->assertOk()
            ->assertSee('Silitibum')
            ->assertDontSee('Shortzz')
            ->assertSee('assets/img/landing/screen-snaps.webp', false);
    }

    public function test_the_creator_share_comes_from_settings(): void
    {
        $this->setting(['creator_share_percent' => 55]);

        $this->get('/')->assertOk()->assertSee('Creators keep 55% of gifts');
    }

    public function test_cash_out_copy_follows_the_withdrawal_switch(): void
    {
        $this->setting(['is_withdrawal_on' => 0]);
        $this->get('/')->assertOk()
            ->assertSee('opening soon')
            ->assertDontSee('Withdraw to mobile money');

        $this->setting(['is_withdrawal_on' => 1]);
        $this->get('/')->assertOk()
            ->assertSee('Withdraw to mobile money')
            ->assertDontSee('opening soon');
    }

    public function test_the_admin_signs_in_at_admin(): void
    {
        $this->get('/admin')->assertOk()->assertSee('noindex', false);
    }

    public function test_admin_pages_send_signed_out_visitors_to_the_admin_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('admin.login'));
        $this->get('/users')->assertRedirect(route('admin.login'));
    }

    public function test_legal_pages_carry_the_app_name(): void
    {
        $this->setting(['app_name' => 'Silitibum']);

        $this->get('/privacy_policy')->assertOk()->assertSee('Privacy Policy &middot; Silitibum', false);
        $this->get('/terms_of_uses')->assertOk()->assertSee('Terms of Use &middot; Silitibum', false);
    }
}
