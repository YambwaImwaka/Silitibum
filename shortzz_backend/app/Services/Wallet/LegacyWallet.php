<?php

namespace App\Services\Wallet;

use App\Models\GlobalSettings;
use Illuminate\Support\Facades\DB;

// Converts a wallet from before the ledger (one fungible coin balance) into
// Coins + Earnings, with opening ledger rows.
//
// The split: coins the user RECEIVED as gifts (up to their current balance)
// become earnings at the creator share of what buyers actually paid per coin
// before the ledger (receivedCoinRate — gifts from then cannot be traced to
// the buyer who paid for them, so it is the average over all of them); the
// rest stay spendable coins, valued at what this user actually paid per coin
// in completed top-ups (coins beyond what they bought are free). The old
// coin_value plays no part: it was above what coins sold for.
//
// It runs in two places with identical results: in bulk from
// wallet:migrate-earnings, and lazily from WalletLedger the first time any
// wallet operation touches a not-yet-converted user. The lazy path is what
// makes the deploy safe: a gift or top-up that lands after the new code goes
// live but before the bulk command runs converts that wallet first, instead of
// mixing new ledger rows into an unconverted balance.
final class LegacyWallet
{
    /// Whether this (locked) row still needs converting: it holds coins and
    /// has never been touched by the ledger.
    public static function needsConversion(int $userId, object $row): bool
    {
        return (int) $row->coin_wallet > 0
            && !DB::table('wallet_transactions')->where('user_id', $userId)->exists();
    }

    /// What buyers paid for coins before the ledger: completed top-ups with
    /// no ledger credit (every top-up completed since carries one). Fixed
    /// once the new code is live, so a wallet converted lazily later gets the
    /// same rate as the bulk run.
    public static function legacyPurchases(): object
    {
        return DB::table('tbl_coin_topup_request as t')
            ->where('t.status', 'completed')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('wallet_transactions as w')->whereColumn('w.topup_id', 't.id');
            })
            ->selectRaw('COALESCE(SUM(t.coins), 0) AS coins, COALESCE(SUM(t.amount), 0) AS amount')
            ->first();
    }

    /// Units of earnings per legacy received coin: the creator share of the
    /// average price buyers paid per coin.
    public static function receivedCoinRate(): int
    {
        $bought = self::legacyPurchases();
        if ((int) $bought->coins <= 0) {
            return 0;
        }
        return intdiv(Money::toUnits((string) $bought->amount) * WalletLedger::sharePercent(), 100 * (int) $bought->coins);
    }

    /// The split, without writing anything.
    public static function plan(int $userId): ?array
    {
        $user = DB::table('tbl_users')->where('id', $userId)
            ->first(['coin_wallet', 'coin_collected_lifetime', 'earnings_balance']);
        if ($user === null || (int) $user->coin_wallet <= 0) {
            return null;
        }
        $settings = GlobalSettings::first();
        $coinValueUnits = self::receivedCoinRate();
        $bonus = ($settings->registration_bonus_status ?? 0) == 1 ? (int) $settings->registration_bonus_amount : 0;

        $held = (int) $user->coin_wallet;
        $earnedCoins = min($held, max(0, (int) $user->coin_collected_lifetime - $bonus));
        $keptCoins = $held - $earnedCoins;

        $topups = DB::table('tbl_coin_topup_request')->where('user_id', $userId)->where('status', 'completed')
            ->selectRaw('COALESCE(SUM(coins), 0) AS coins, COALESCE(SUM(amount), 0) AS amount')->first();
        $boughtCoins = (int) $topups->coins;
        $valuedCoins = min($keptCoins, $boughtCoins);

        return [
            'held' => $held,
            'earned_coins' => $earnedCoins,
            'earnings' => $earnedCoins * $coinValueUnits,
            'kept_coins' => $keptCoins,
            'kept_value' => Money::proportion(Money::toUnits((string) $topups->amount), $valuedCoins, $boughtCoins),
            'coin_value' => $coinValueUnits,
            'earnings_before' => Money::toUnits((string) $user->earnings_balance),
        ];
    }

    /// Converts the wallet. The caller must hold the user's row lock inside a
    /// transaction. Idempotent: the opening rows carry fixed keys.
    public static function convert(int $userId): ?array
    {
        $plan = self::plan($userId);
        if ($plan === null) {
            return null;
        }
        $meta = json_encode([
            'legacy_coins' => $plan['held'],
            'converted_coins' => $plan['earned_coins'],
            'rate_per_coin' => Money::fromUnits($plan['coin_value']),
        ]);
        $now = now();
        $rows = [];
        if ($plan['kept_coins'] > 0) {
            $rows[] = [
                'user_id' => $userId, 'account' => WalletLedger::ACCOUNT_COINS, 'type' => WalletLedger::TYPE_MIGRATION_OPENING,
                'coins' => $plan['kept_coins'], 'amount' => Money::fromUnits($plan['kept_value']),
                'balance_after_coins' => $plan['kept_coins'], 'balance_after_amount' => Money::fromUnits($plan['kept_value']),
                'idempotency_key' => 'migration:coins:' . $userId, 'meta' => $meta, 'created_at' => $now,
            ];
        }
        // Written whenever coins were converted, even at a zero coin_value, so
        // every converted coin leaves a record (and the user never looks
        // unconverted again).
        if ($plan['earned_coins'] > 0) {
            $rows[] = [
                'user_id' => $userId, 'account' => WalletLedger::ACCOUNT_EARNINGS, 'type' => WalletLedger::TYPE_MIGRATION_OPENING,
                'amount' => Money::fromUnits($plan['earnings']),
                'balance_after_amount' => Money::fromUnits($plan['earnings_before'] + $plan['earnings']),
                'idempotency_key' => 'migration:earnings:' . $userId, 'meta' => $meta, 'created_at' => $now,
            ];
        }
        foreach ($rows as $row) {
            DB::table('wallet_transactions')->insert($row);
        }
        DB::table('tbl_users')->where('id', $userId)->update([
            'coin_wallet' => $plan['kept_coins'],
            'coin_wallet_value' => Money::fromUnits($plan['kept_value']),
            'earnings_balance' => DB::raw('earnings_balance + ' . Money::fromUnits($plan['earnings'])),
            'earnings_lifetime' => DB::raw('earnings_lifetime + ' . Money::fromUnits($plan['earnings'])),
        ]);
        return $plan;
    }
}
