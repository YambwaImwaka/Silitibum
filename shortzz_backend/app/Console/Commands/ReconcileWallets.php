<?php

namespace App\Console\Commands;

use App\Services\Wallet\Money;
use App\Services\Wallet\WalletLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Proves every balance against the ledger.
//
// WalletLedger writes each movement and its balance change in one transaction,
// so for every user: coin_wallet = Σ coins, coin_wallet_value = Σ amount (coins
// account) and earnings_balance = Σ amount (earnings account). A mismatch means
// something changed a balance outside WalletLedger — exactly the kind of bug
// that used to mint coins — so it is logged loudly and the run fails.
class ReconcileWallets extends Command
{
    protected $signature = 'wallet:reconcile';

    protected $description = 'Check every wallet balance against the sum of its ledger rows.';

    public function handle(): int
    {
        $ledger = DB::table('wallet_transactions')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw("user_id,
                SUM(CASE WHEN account = 'coins' THEN coins ELSE 0 END) AS coins,
                SUM(CASE WHEN account = 'coins' THEN amount ELSE 0 END) AS coin_value,
                SUM(CASE WHEN account = 'earnings' THEN amount ELSE 0 END) AS earnings")
            ->get()
            ->keyBy('user_id');

        $mismatches = [];
        $unconverted = 0;
        DB::table('tbl_users')
            ->where(function ($q) use ($ledger) {
                $q->where('coin_wallet', '!=', 0)
                    ->orWhere('coin_wallet_value', '!=', 0)
                    ->orWhere('earnings_balance', '!=', 0)
                    ->orWhereIn('id', $ledger->keys()->all());
            })
            ->orderBy('id')
            ->chunk(500, function ($users) use ($ledger, &$mismatches, &$unconverted) {
                foreach ($users as $user) {
                    $row = $ledger->get($user->id);
                    // A wallet from before the ledger that nothing has touched
                    // yet: not a discrepancy, it just has not been converted
                    // (wallet:migrate-earnings, or its first wallet operation).
                    if ($row === null && (int) $user->coin_wallet > 0
                        && Money::toUnits((string) $user->coin_wallet_value) === 0
                        && Money::toUnits((string) $user->earnings_balance) === 0) {
                        $unconverted++;
                        continue;
                    }
                    $expected = [
                        'coin_wallet' => (int) ($row->coins ?? 0),
                        'coin_wallet_value' => Money::toUnits((string) ($row->coin_value ?? '0')),
                        'earnings_balance' => Money::toUnits((string) ($row->earnings ?? '0')),
                    ];
                    $actual = [
                        'coin_wallet' => (int) $user->coin_wallet,
                        'coin_wallet_value' => Money::toUnits((string) $user->coin_wallet_value),
                        'earnings_balance' => Money::toUnits((string) $user->earnings_balance),
                    ];
                    foreach ($expected as $field => $value) {
                        if ($actual[$field] !== $value) {
                            $mismatches[] = [$user->id, $field, self::show($field, $actual[$field]), self::show($field, $value)];
                        }
                    }
                }
            });

        $platform = DB::table('wallet_transactions')->where('account', WalletLedger::ACCOUNT_PLATFORM)->sum('amount');
        $this->info('Platform commission to date: ' . Money::format(Money::toUnits((string) $platform)));
        if ($unconverted > 0) {
            $this->warn("{$unconverted} wallet(s) from before the ledger not converted yet — run wallet:migrate-earnings.");
        }

        if ($mismatches) {
            $this->table(['user', 'field', 'balance', 'ledger says'], $mismatches);
            Log::error('wallet:reconcile found ' . count($mismatches) . ' balance(s) that do not match the ledger', ['mismatches' => $mismatches]);
            return self::FAILURE;
        }
        $this->info('All balances match the ledger.');
        return self::SUCCESS;
    }

    private static function show(string $field, int $value): string
    {
        return $field === 'coin_wallet' ? (string) $value : Money::fromUnits($value);
    }
}
