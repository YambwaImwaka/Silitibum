<?php

namespace App\Services\Verification;

use App\Models\GlobalFunction;
use App\Models\GlobalSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// The only code that changes tbl_users.is_verify (the blue tick), and it
// records where each tick came from so a source can end only its own kind:
//   admin — granted in the admin panel to anyone, never expires;
//   paid  — bought with mobile money on the web (Lenco), lasts
//           settings.verification_days (0 = never expires);
//   store — a Play / App Store subscription (RevenueCat).
// An admin-granted tick is never downgraded by a purchase or a lapse.
class BlueTick
{
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_PAID = 'paid';
    public const SOURCE_STORE = 'store';

    public static function grantByAdmin(int $userId): void
    {
        DB::table('tbl_users')->where('id', $userId)->update([
            'is_verify' => 1,
            'verification_source' => self::SOURCE_ADMIN,
            'verified_until' => null,
        ]);
    }

    public static function revokeByAdmin(int $userId): void
    {
        DB::table('tbl_users')->where('id', $userId)->update([
            'is_verify' => 0,
            'verification_source' => null,
            'verified_until' => null,
        ]);
    }

    /** Adds one paid period, from the end of the current one if it is still
     * running. Returns when it now ends (null: never), or false for an
     * admin-granted tick, which a purchase leaves alone. Call inside a
     * transaction: the user row is locked. */
    public static function extendPaid(int $userId, int $days)
    {
        $user = DB::table('tbl_users')->where('id', $userId)->lockForUpdate()
            ->first(['id', 'is_verify', 'verification_source', 'verified_until']);
        if ($user === null) {
            return false;
        }
        if ($user->is_verify == 1 && $user->verification_source === self::SOURCE_ADMIN) {
            return false;
        }

        $now = Carbon::now();
        $start = $now;
        if ($user->is_verify == 1 && $user->verification_source === self::SOURCE_PAID) {
            if ($user->verified_until === null) {
                return null; // already paid for good
            }
            $current = Carbon::parse($user->verified_until);
            if ($current->gt($now)) {
                $start = $current;
            }
        }
        $until = $days > 0 ? $start->copy()->addDays($days) : null;

        DB::table('tbl_users')->where('id', $userId)->update([
            'is_verify' => 1,
            'verification_source' => self::SOURCE_PAID,
            'verified_until' => $until,
        ]);
        return $until;
    }

    /** A store subscription started or renewed. */
    public static function grantByStore(int $userId): void
    {
        DB::table('tbl_users')->where('id', $userId)
            ->where(function ($query) {
                $query->whereNull('verification_source')
                    ->orWhere('verification_source', self::SOURCE_STORE);
            })
            ->update([
                'is_verify' => 1,
                'verification_source' => self::SOURCE_STORE,
                'verified_until' => null,
            ]);
    }

    /** A store subscription ended: only a store-sourced tick goes. */
    public static function revokeStore(int $userId): void
    {
        DB::table('tbl_users')->where('id', $userId)
            ->where(function ($query) {
                $query->whereNull('verification_source')
                    ->orWhere('verification_source', self::SOURCE_STORE);
            })
            ->update([
                'is_verify' => 0,
                'verification_source' => null,
                'verified_until' => null,
            ]);
    }

    /** Ends paid ticks whose period is over; returns how many. */
    public static function expireDue(): int
    {
        $due = DB::table('tbl_users')
            ->where('is_verify', 1)
            ->where('verification_source', self::SOURCE_PAID)
            ->whereNotNull('verified_until')
            ->where('verified_until', '<=', Carbon::now())
            ->pluck('id');

        $expired = 0;
        foreach ($due as $userId) {
            // Conditional on the period still being over: a renewal landing
            // at the same moment wins.
            $updated = DB::table('tbl_users')->where('id', $userId)
                ->where('verification_source', self::SOURCE_PAID)
                ->where('verified_until', '<=', Carbon::now())
                ->update(['is_verify' => 0, 'verification_source' => null, 'verified_until' => null]);
            if ($updated === 1) {
                $expired++;
                GlobalFunction::pushToUser((int) $userId, 'Your blue tick has expired',
                    'Renew it any time from the Get Verified page.', ['type' => 'verification']);
            }
        }
        if ($expired > 0) {
            Log::info("BlueTick: expired {$expired} paid blue tick(s)");
        }
        return $expired;
    }

    /** What a user's verification looks like, for pages and the API. */
    public static function status($user): array
    {
        $settings = GlobalSettings::first();
        return [
            'verified' => (int) ($user->is_verify ?? 0) === 1,
            'source' => $user->verification_source ?? null,
            'verified_until' => $user->verified_until ?? null,
            'price' => (string) ($settings->verification_price ?? '50.00'),
            'days' => (int) ($settings->verification_days ?? 30),
            'enabled' => (int) ($settings->verification_enabled ?? 1) === 1,
            'currency' => $settings->currency ?? 'K',
        ];
    }
}
