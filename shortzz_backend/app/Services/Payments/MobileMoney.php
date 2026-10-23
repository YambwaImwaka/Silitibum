<?php

namespace App\Services\Payments;

use App\Models\GlobalFunction;

// Zambian mobile-money number handling, shared by collections (web top-up),
// payouts and the withdrawal form. Moved out of LencoGateway so withdrawals can
// check a number against the network the creator picked.
final class MobileMoney
{
    public const OPERATORS = [
        'airtel' => 'Airtel Money',
        'mtn' => 'MTN MoMo',
        'zamtel' => 'Zamtel Kwacha',
    ];

    // Zambian mobile prefixes, national form: MTN 096/076, Airtel 097/077,
    // Zamtel 095/075. Lenco requires the operator explicitly and there is no
    // lookup endpoint for it, so it has to come from the prefix — asking the
    // user to pick their network on top of typing their number is exactly the
    // kind of friction the top-up page is trying to avoid.
    public static function operatorFor(string $phone): ?string
    {
        $prefix = substr(self::localPhone($phone), 0, 3);
        return match ($prefix) {
            '096', '076' => 'mtn',
            '097', '077' => 'airtel',
            '095', '075' => 'zamtel',
            default => null,
        };
    }

    // National form with the trunk zero ('0971234567'), which is what Lenco's
    // own examples send for zm and what an admin paying by hand dials.
    public static function localPhone(string $phone): string
    {
        $msisdn = GlobalFunction::canonicalMsisdn($phone) ?? preg_replace('/[^0-9]/', '', $phone);
        $code = (string) config('app.default_country_code');
        if ($code !== '' && str_starts_with($msisdn, $code)) {
            return '0' . substr($msisdn, strlen($code));
        }
        return $msisdn;
    }

    public static function label(?string $operator): string
    {
        return self::OPERATORS[$operator] ?? 'Mobile Money';
    }
}
