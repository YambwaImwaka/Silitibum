<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\GatewayResult;
use App\Services\Payments\LencoGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// 'failed' must only ever mean "the provider said it failed". Every other
// error leaves the transaction open for the sweep — a 'failed' verdict buries
// a top-up for good and refunds a payout.
class GatewaySemanticsTest extends TestCase
{
    private function lenco(): LencoGateway
    {
        return new LencoGateway('test-key');
    }

    public function test_status_check_that_cannot_connect_is_not_a_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $this->lenco()->verify('topup_7');

        $this->assertSame(GatewayResult::PROCESSING, $result['status']);
        $this->assertTrue($result['unconfirmed']);
    }

    public function test_status_check_server_error_is_not_a_failure(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Internal error'], 502)]);

        $this->assertSame(GatewayResult::PROCESSING, $this->lenco()->verify('topup_7')['status']);
    }

    public function test_status_check_not_found_is_open_and_flagged(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Collection details was not found', 'errorCode' => '10'], 400)]);

        $result = $this->lenco()->verify('topup_7');

        $this->assertSame(GatewayResult::PROCESSING, $result['status']);
        $this->assertTrue($result['not_found']);
    }

    public function test_status_check_reporting_failed_is_a_failure(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => ['status' => 'failed', 'reasonForFailure' => 'Insufficient balance']], 200)]);

        $result = $this->lenco()->verify('topup_7');

        $this->assertSame(GatewayResult::FAILED, $result['status']);
        $this->assertSame('Insufficient balance', $result['message']);
    }

    public function test_status_check_reporting_success_completes(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => ['status' => 'successful', 'lencoReference' => '2620809422']], 200)]);

        $this->assertSame(GatewayResult::COMPLETED, $this->lenco()->verify('topup_7')['status']);
    }

    public function test_charge_rejected_by_validation_fails(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Invalid phone number'], 400)]);

        $result = $this->lenco()->charge('260971234567', 5.0, 'ZMW', 'topup_7');

        $this->assertSame(GatewayResult::FAILED, $result['status']);
    }

    public function test_charge_duplicate_reference_is_open(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Duplicate reference'], 400)]);

        $this->assertSame(GatewayResult::PROCESSING, $this->lenco()->charge('260971234567', 5.0, 'ZMW', 'topup_7')['status']);
    }

    public function test_charge_timeout_is_open(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $this->lenco()->charge('260971234567', 5.0, 'ZMW', 'topup_7');

        $this->assertSame(GatewayResult::PROCESSING, $result['status']);
        $this->assertTrue($result['unconfirmed']);
    }

    public function test_charge_throttled_is_open(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Too many requests'], 429)]);

        $this->assertSame(GatewayResult::PROCESSING, $this->lenco()->charge('260971234567', 5.0, 'ZMW', 'topup_7')['status']);
    }
}
