<?php

namespace Tests\Feature;

use Tests\Support\ApiTestCase;

class ProfilePrivacyTest extends ApiTestCase
{
    private const PRIVATE_FIELDS = ['identity', 'user_email', 'user_mobile_no', 'lat', 'lon', 'coin_wallet', 'coin_purchased_lifetime', 'coin_gifted_lifetime', 'provider_uid', 'city'];

    private function person(): object
    {
        return $this->makeUser([
            'identity' => 'private@example.com',
            'user_email' => 'private@example.com',
            'user_mobile_no' => '0971234567',
            'lat' => -15.4,
            'lon' => 28.3,
            'city' => 'Lusaka',
            'coin_wallet' => 999,
            'show_my_following' => 0,
        ]);
    }

    public function test_guest_sees_only_the_public_profile(): void
    {
        $person = $this->person();

        $data = $this->api('user/fetchUserDetails', ['user_id' => $person->id])->assertJson(['status' => true])->json('data');

        foreach (self::PRIVATE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data, "$field leaked to a guest");
        }
        $this->assertSame($person->username, $data['username']);
        $this->assertArrayHasKey('is_freez', $data);
        $this->assertArrayHasKey('show_my_following', $data);
        $this->assertArrayHasKey('follow_status', $data);
    }

    public function test_another_user_sees_only_the_public_profile(): void
    {
        $person = $this->person();
        $other = $this->makeUser();

        $data = $this->api('user/fetchUserDetails', ['user_id' => $person->id], $this->tokenFor($other))->json('data');

        foreach (self::PRIVATE_FIELDS as $field) {
            $this->assertArrayNotHasKey($field, $data, "$field leaked to another user");
        }
    }

    public function test_owner_still_gets_their_full_profile(): void
    {
        $person = $this->person();

        $data = $this->api('user/fetchUserDetails', ['user_id' => $person->id], $this->tokenFor($person))->json('data');

        $this->assertSame('private@example.com', $data['identity']);
        $this->assertEquals(999, $data['coin_wallet']);
    }
}
