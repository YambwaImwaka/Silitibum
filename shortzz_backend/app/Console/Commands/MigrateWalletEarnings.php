<?php

namespace App\Console\Commands;

use App\Models\GlobalSettings;
use App\Services\Wallet\LegacyWallet;
use App\Services\Wallet\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Bulk conversion of every wallet from before the ledger (see LegacyWallet for
// the split). WalletLedger converts a wallet by itself the first time any
// wallet operation touches it, so running this is about getting everyone
// converted at once — and about the dry run, which shows exactly what each
// user will get before anything is written. Safe to re-run: converted wallets
// have ledger rows and are skipped.
class MigrateWalletEarnings extends Command
{
    protected $signature = 'wallet:migrate-earnings {--dry-run : Show the split without writing anything}';

    protected $description = 'Split legacy coin balances into spendable coins and withdrawable earnings, with opening ledger rows.';

    public function handle(): int
    {
        $settings = GlobalSettings::first();
        $dryRun = (bool) $this->option('dry-run');
        $currency = $settings->currency ?? '';
        $bonus = ($settings->registration_bonus_status ?? 0) == 1 ? (int) $settings->registration_bonus_amount : 0;
        $bought = LegacyWallet::legacyPurchases();
        $this->info(sprintf(
            'Received coins convert at %s%s each: %d%% of what buyers paid per coin before the ledger (%s%s for %d coins). Registration bonus deducted from "collected": %d',
            $currency, Money::fromUnits(LegacyWallet::receivedCoinRate()),
            \App\Services\Wallet\WalletLedger::sharePercent(),
            $currency, Money::format(Money::toUnits((string) $bought->amount)), (int) $bought->coins,
            $bonus
        ));

        // "Collected" should be gift coins only. Before the ledger, the sign-up
        // bonus and admin grants were counted in it too; if the totals differ,
        // some of what converts to cash below was never a gift.
        $totals = DB::table('tbl_users')->selectRaw('COALESCE(SUM(coin_collected_lifetime), 0) AS collected, COALESCE(SUM(coin_gifted_lifetime), 0) AS gifted')->first();
        if ((int) $totals->collected !== (int) $totals->gifted) {
            $this->warn("Lifetime coins collected ({$totals->collected}) != gifted ({$totals->gifted}): bonus or admin coins may be counted as received gifts. Review the rows below before converting.");
        } else {
            $this->info("Lifetime coins collected ({$totals->collected}) == gifted ({$totals->gifted}): every received coin came from a gift.");
        }

        $userIds = DB::table('tbl_users')->where('coin_wallet', '>', 0)->orderBy('id')->pluck('id');
        $rows = [];
        $sum = ['earnings' => 0, 'value' => 0, 'coins' => 0];
        foreach ($userIds as $userId) {
            $plan = DB::transaction(function () use ($userId, $dryRun) {
                $row = DB::table('tbl_users')->where('id', $userId)->lockForUpdate()->first(['id', 'coin_wallet']);
                if ($row === null || !LegacyWallet::needsConversion((int) $userId, $row)) {
                    return null; // already converted (here, or lazily by WalletLedger)
                }
                return $dryRun ? LegacyWallet::plan((int) $userId) : LegacyWallet::convert((int) $userId);
            });
            if ($plan === null) {
                continue;
            }
            $username = DB::table('tbl_users')->where('id', $userId)->value('username');
            $rows[] = [
                $userId, $username, $plan['held'], $plan['earned_coins'],
                $currency . Money::format($plan['earnings']), $plan['kept_coins'], $currency . Money::format($plan['kept_value']),
            ];
            $sum['earnings'] += $plan['earnings'];
            $sum['value'] += $plan['kept_value'];
            $sum['coins'] += $plan['kept_coins'];
        }

        $this->table(['user', 'username', 'coins before', 'coins -> earnings', 'earnings', 'coins kept', 'value of kept coins'], $rows);
        $this->info(sprintf(
            '%s%d wallets. Earnings owed to creators: %s%s. Spendable coins kept: %d, worth %s%s.',
            $dryRun ? '[dry run — nothing written] ' : '',
            count($rows),
            $currency, Money::format($sum['earnings']),
            $sum['coins'],
            $currency, Money::format($sum['value'])
        ));
        return self::SUCCESS;
    }
}
