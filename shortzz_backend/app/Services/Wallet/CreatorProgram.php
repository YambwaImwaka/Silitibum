<?php

namespace App\Services\Wallet;

use App\Models\CreatorProfile;
use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use App\Services\Payments\LencoGateway;
use App\Services\Payments\MobileMoney;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Support\Carbon;

// Who may cash out, and to where.
//
// Anyone can RECEIVE gifts and build up earnings; only withdrawing is gated.
// The gates exist because a withdrawal is where fraud turns into money:
// throwaway accounts (account age), stolen sessions (a registered payout
// number that changes only with a hold), and someone else's mobile-money
// wallet (the network's registered name must match the creator's legal name
// wherever the payout provider can look it up).
final class CreatorProgram
{
    /// The checklist the app shows, and whether every item passes.
    public static function eligibility(object $user, ?CreatorProfile $profile): array
    {
        $settings = GlobalSettings::first();
        $minAgeDays = (int) ($settings->creator_min_account_age_days ?? 30);
        $holdHours = (int) ($settings->payout_change_hold_hours ?? 72);

        $createdAt = $user->created_at ? Carbon::parse($user->created_at) : null;
        $ageOk = $createdAt !== null && $createdAt->lte(now()->subDays($minAgeDays));
        $holdUntil = ($profile && $profile->payout_changed_at) ? $profile->payout_changed_at->copy()->addHours($holdHours) : null;

        $checks = [
            [
                'key' => 'account_age',
                'ok' => $ageOk,
                'label' => "Account at least {$minAgeDays} days old",
                'available_at' => (!$ageOk && $createdAt) ? $createdAt->copy()->addDays($minAgeDays)->toIso8601String() : null,
            ],
            [
                'key' => 'good_standing',
                'ok' => $user->is_freez != 1 && $user->is_dummy != 1 && ($profile === null || $profile->status !== 'suspended'),
                'label' => 'Account in good standing',
            ],
            [
                'key' => 'payout_account',
                'ok' => $profile !== null && $profile->adult_confirmed_at !== null,
                'label' => 'Mobile money account added (18+)',
            ],
            [
                // Only a known mismatch blocks: where no lookup was possible
                // ('unverified'), an admin paying by hand sees the registered
                // name in their own mobile-money app before confirming.
                'key' => 'name_match',
                'ok' => $profile !== null && ($profile->name_match !== 'mismatch' || !empty($profile->name_override_by)),
                'label' => 'Mobile money account is in your name',
            ],
            [
                'key' => 'payout_change_hold',
                'ok' => $holdUntil === null || $holdUntil->isPast(),
                'label' => 'Payout number not changed in the last ' . $holdHours . ' hours',
                'available_at' => ($holdUntil !== null && !$holdUntil->isPast()) ? $holdUntil->toIso8601String() : null,
            ],
        ];

        return [
            'eligible' => collect($checks)->every(fn ($check) => $check['ok']),
            'checks' => $checks,
        ];
    }

    /// Registers or replaces the creator's payout account. Returns the saved
    /// profile, or an error message.
    public static function savePayoutAccount(object $user, string $legalName, string $phone, string $operator): CreatorProfile|string
    {
        $legalName = trim(preg_replace('/\s+/', ' ', $legalName));
        if (mb_strlen($legalName) < 3 || count(self::nameTokens($legalName)) < 2) {
            return 'please enter your full legal name (first and last name)';
        }
        $msisdn = GlobalFunction::canonicalMsisdn($phone);
        if ($msisdn === null || MobileMoney::operatorFor($msisdn) === null) {
            return 'please enter a valid Zambian mobile money number';
        }
        if (MobileMoney::operatorFor($msisdn) !== $operator) {
            return 'this number is not a ' . MobileMoney::label($operator) . ' number';
        }
        $localPhone = MobileMoney::localPhone($msisdn);

        $registeredName = self::lookUpRegisteredName($msisdn);
        $nameMatch = $registeredName === null ? 'unverified'
            : (self::namesMatch($legalName, $registeredName) ? 'match' : 'mismatch');

        $profile = CreatorProfile::find($user->id) ?? new CreatorProfile();
        $profile->user_id = $user->id;
        $numberChanged = $profile->exists && $profile->payout_phone !== $localPhone;
        $nameChanged = $profile->exists && $profile->legal_name !== $legalName;
        if ($numberChanged || $nameChanged) {
            // A changed destination is exactly what a hijacked session would
            // do: hold withdrawals for a while and tell the owner.
            $profile->payout_changed_at = now();
            $profile->name_override_by = null;
        }
        $profile->legal_name = $legalName;
        $profile->payout_phone = $localPhone;
        $profile->payout_operator = $operator;
        $profile->payout_account_name = $registeredName;
        $profile->name_match = $nameMatch;
        $profile->adult_confirmed_at = $profile->adult_confirmed_at ?? now();
        $profile->save();

        if ($numberChanged) {
            GlobalFunction::pushToUser((int) $user->id, 'Payout number changed',
                'Your Silitibum payout number was changed. If this was not you, contact support right away.',
                ['type' => 'payout_account']);
        }
        return $profile;
    }

    /// What the app may show about the saved account — never the name the
    /// network has on file.
    public static function publicProfile(?CreatorProfile $profile): ?array
    {
        if ($profile === null) {
            return null;
        }
        $phone = (string) $profile->payout_phone;
        return [
            'legal_name' => $profile->legal_name,
            'payout_phone_masked' => strlen($phone) > 4 ? str_repeat('•', strlen($phone) - 4) . substr($phone, -4) : $phone,
            'payout_operator' => $profile->payout_operator,
            'payout_operator_label' => MobileMoney::label($profile->payout_operator),
            'name_match' => $profile->name_match,
            'name_override' => !empty($profile->name_override_by),
            'status' => $profile->status,
        ];
    }

    /// Two names match when they share at least two name tokens, or one if
    /// either name only has one (ID registrations often drop middle names
    /// and reorder the rest).
    public static function namesMatch(string $a, string $b): bool
    {
        $tokensA = self::nameTokens($a);
        $tokensB = self::nameTokens($b);
        if (!$tokensA || !$tokensB) {
            return false;
        }
        $shared = count(array_intersect($tokensA, $tokensB));
        $needed = min(2, count($tokensA), count($tokensB));
        return $shared >= $needed;
    }

    private static function nameTokens(string $name): array
    {
        $clean = strtoupper(preg_replace('/[^\p{L} ]+/u', ' ', $name));
        return array_values(array_unique(array_filter(explode(' ', $clean), fn ($t) => mb_strlen($t) >= 2)));
    }

    private static function lookUpRegisteredName(string $msisdn): ?string
    {
        $settings = GlobalSettings::first();
        if (empty($settings->lenco_api_key)) {
            return null;
        }
        $gateway = PaymentGatewayFactory::forProvider('lenco', $settings);
        return $gateway instanceof LencoGateway ? $gateway->resolveMobileMoney($msisdn) : null;
    }
}
