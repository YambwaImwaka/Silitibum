<?php

namespace App\Services\Wallet;

// Kwacha amounts as integer "units" of 1/10 000 K, matching the DECIMAL(14,4)
// columns they are stored in. All wallet arithmetic happens on these ints, so
// a gift split can never pick up float noise (0.1 + 0.2) and needs no bcmath,
// which is not guaranteed on the production PHP build.
final class Money
{
    public const SCALE = 10000;

    /// Accepts what the DB and the app hand us: DECIMAL strings ("8.3333"),
    /// ints, and the FLOAT coin_plan_price column. Strings are parsed exactly;
    /// floats are rounded to the nearest unit.
    public static function toUnits($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * self::SCALE;
        }
        if (is_string($value) && preg_match('/^\s*(-?)(\d*)(?:\.(\d*))?\s*$/', $value, $m)) {
            $whole = (int) ($m[2] === '' ? '0' : $m[2]);
            $fraction = substr(str_pad($m[3] ?? '', 5, '0'), 0, 5);
            // Round half up on the fifth decimal.
            $units = $whole * self::SCALE + intdiv((int) $fraction + 5, 10);
            return $m[1] === '-' ? -$units : $units;
        }
        return (int) round(((float) $value) * self::SCALE);
    }

    /// "8.3333" — what DECIMAL(14,4) columns and the API carry.
    public static function fromUnits(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $abs = abs($units);
        return $sign . intdiv($abs, self::SCALE) . '.' . str_pad((string) ($abs % self::SCALE), 4, '0', STR_PAD_LEFT);
    }

    /// Two decimals for display and for payout amounts ("8.33").
    public static function format(int $units): string
    {
        return number_format($units / self::SCALE, 2, '.', '');
    }

    /// Value of $part out of $whole, both counts, scaled: intdiv keeps it
    /// exact and rounds down — whoever pays never pays a fraction more.
    public static function proportion(int $units, int $part, int $whole): int
    {
        if ($whole <= 0 || $part <= 0) {
            return 0;
        }
        if ($part >= $whole) {
            return $units;
        }
        return intdiv($units * $part, $whole);
    }
}
