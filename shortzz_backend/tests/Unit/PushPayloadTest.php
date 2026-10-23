<?php

namespace Tests\Unit;

use App\Models\Constants;
use App\Models\GlobalFunction;
use PHPUnit\Framework\TestCase;

// Android must get a `notification` block: data-only messages were never
// shown while the app was closed, because the app only draws background
// messages itself on iOS.
class PushPayloadTest extends TestCase
{
    public function test_android_push_is_shown_by_the_system_and_keeps_routing_data(): void
    {
        $payload = GlobalFunction::generatePushNotificationPayload(
            Constants::android, Constants::pushTypeToken, 'device-token',
            'New follower', '@fan started following you', null,
            ['type' => 'user', 'notification_data' => '{"id":7}']
        );

        $message = $payload['message'];
        $this->assertSame('device-token', $message['token']);
        $this->assertSame(['title' => 'New follower', 'body' => '@fan started following you'], $message['notification']);
        $this->assertSame('shortzz', $message['android']['notification']['channel_id']);
        $this->assertSame('high', $message['android']['priority']);
        // Tap routing and the foreground banner read these.
        $this->assertSame('user', $message['data']['type']);
        $this->assertSame('{"id":7}', $message['data']['notification_data']);
        $this->assertSame('New follower', $message['data']['title']);
    }

    public function test_data_values_are_strings_as_fcm_requires(): void
    {
        $payload = GlobalFunction::generatePushNotificationPayload(
            Constants::android, Constants::pushTypeToken, 'device-token',
            'Gift', 'You got a gift', null, ['id' => 12, 'coins' => 50]
        );

        foreach ($payload['message']['data'] as $value) {
            $this->assertIsString($value);
        }
    }

    public function test_topic_broadcasts_are_shown_too(): void
    {
        $payload = GlobalFunction::generatePushNotificationPayload(
            Constants::android, Constants::pushTypeTopic, 'silitibum_android',
            'Hello', 'News', 'https://example.com/a.jpg'
        );

        $message = $payload['message'];
        $this->assertSame('silitibum_android', $message['topic']);
        $this->assertSame('https://example.com/a.jpg', $message['notification']['image']);
    }
}
