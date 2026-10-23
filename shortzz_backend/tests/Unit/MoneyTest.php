<?php

namespace Tests\Unit;

use App\Services\Wallet\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_strings_are_parsed_exactly(): void
    {
        $this->assertSame(83333, Money::toUnits('8.3333'));
        $this->assertSame(5000, Money::toUnits('0.5'));
        $this->assertSame(500000, Money::toUnits('50.00'));
        $this->assertSame(-83333, Money::toUnits('-8.3333'));
        $this->assertSame(0, Money::toUnits(''));
        $this->assertSame(0, Money::toUnits(null));
    }

    public function test_fifth_decimal_rounds_half_up(): void
    {
        $this->assertSame(83334, Money::toUnits('8.33335'));
        $this->assertSame(83333, Money::toUnits('8.33334'));
        $this->assertSame(100000, Money::toUnits('9.99999'));
    }

    public function test_floats_and_ints(): void
    {
        $this->assertSame(500, Money::toUnits(0.05));      // coin_value
        $this->assertSame(1000000, Money::toUnits(100.0)); // FLOAT coin_plan_price
        $this->assertSame(50000, Money::toUnits(5));
    }

    public function test_formatting(): void
    {
        $this->assertSame('8.3333', Money::fromUnits(83333));
        $this->assertSame('-0.0500', Money::fromUnits(-500));
        $this->assertSame('8.33', Money::format(83333));
        $this->assertSame('0.60', Money::format(6000));
    }

    public function test_proportion_rounds_down_and_takes_all_at_the_end(): void
    {
        $this->assertSame(3333, Money::proportion(10000, 1, 3));
        $this->assertSame(10000, Money::proportion(10000, 3, 3));
        $this->assertSame(10000, Money::proportion(10000, 5, 3));
        $this->assertSame(0, Money::proportion(10000, 0, 3));
        $this->assertSame(0, Money::proportion(10000, 1, 0));
    }
}
