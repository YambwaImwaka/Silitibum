<?php

namespace App\Console\Commands;

use App\Models\CoinTopUpRequests;
use App\Models\GlobalSettings;
use App\Services\Payments\GatewayResult;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Payments\TopUpReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// Safety net for top-ups whose webhook never arrives.
//
// The webhook is the fast path, not a guarantee: top-up 1 (user 42, 10 coins)
// was charged successfully at Lenco on 2026-07-27 and NO webhook was ever
// delivered for it — the access log shows deliveries only for top-up 2. The
// row sat in 'processing' indefinitely, so a customer who really paid simply
// never received coins and nothing anywhere flagged it. Without this sweep
// that outcome is silent and permanent for every future user it happens to.
//
// Asking the provider directly is the authoritative check the webhook was only
// ever a trigger for, so re-running it on a schedule costs nothing and closes
// the hole. Crediting goes through TopUpReconciler, the same atomic claim the
// webhook uses, so a sweep racing a late webhook still credits exactly once.
//
// Verdicts follow GatewayResult: only the provider saying "failed" fails a
// top-up. A reference the provider has never heard of is NOT a failure until
// --expire-hours have passed — Lenco answers "not found" for a while before a
// collection is registered, and before 2026-10-18 that answer buried paid
// top-ups as failed. --include-failed re-checks those older rows.
class ReconcileTopUps extends Command
{
    protected $signature = 'topups:reconcile
                            {--grace=2 : Minutes to let the webhook land before chasing a top-up}
                            {--days=30 : Ignore top-ups older than this}
                            {--expire-hours=24 : Fail a top-up only after the provider has not found it for this long}
                            {--include-failed : Also re-verify rows already marked failed, crediting any the provider confirms as paid}
                            {--dry-run : Report what would happen without writing}';

    protected $description = 'Re-verify non-final coin top-ups with the payment provider and credit any the provider confirms as paid.';

    public function handle(): int
    {
        $settings = GlobalSettings::first();
        if ($settings === null) {
            $this->error('No settings row; cannot resolve a gateway.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $statuses = ['pending', 'processing'];
        if ($this->option('include-failed')) {
            $statuses[] = 'failed';
        }

        $pending = CoinTopUpRequests::whereIn('status', $statuses)
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('grace')))
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('Nothing to reconcile.');
            return self::SUCCESS;
        }

        $credited = 0;
        $failed = 0;
        $stillPending = 0;

        foreach ($pending as $topup) {
            $gateway = PaymentGatewayFactory::forProvider($topup->provider, $settings);
            if ($gateway === null) {
                $this->warn("top-up {$topup->id}: no gateway for provider '{$topup->provider}', skipping");
                continue;
            }

            $verdict = $this->verdictFor($topup, $gateway);

            // A row that is already failed is only ever rescued, never
            // re-failed or reopened.
            if ($topup->status === 'failed' && $verdict !== GatewayResult::COMPLETED) {
                $this->line("top-up {$topup->id}: still not paid at the provider ({$verdict}), left as failed");
                continue;
            }

            if ($dryRun) {
                $this->line("top-up {$topup->id} (user {$topup->user_id}, {$topup->coins} coins, K{$topup->amount}): {$topup->status} -> {$verdict} [dry run]");
                continue;
            }

            if (TopUpReconciler::apply($topup, $verdict)) {
                $credited++;
                $this->info("top-up {$topup->id}: credited {$topup->coins} coins to user {$topup->user_id} (was {$topup->status})");
                Log::warning("ReconcileTopUps rescued top-up {$topup->id} for user {$topup->user_id} — the provider confirmed payment but no webhook had credited it (status was {$topup->status}).");
            } elseif ($verdict === GatewayResult::FAILED) {
                $failed++;
                $this->line("top-up {$topup->id}: marked failed");
            } else {
                $stillPending++;
                $this->line("top-up {$topup->id}: still {$verdict}, leaving for the next run");
            }
        }

        $this->info("Done. credited={$credited} failed={$failed} still-pending={$stillPending}");
        return self::SUCCESS;
    }

    /// Asks the provider about every reference the top-up may be known by.
    ///
    /// Any reference reporting success wins: top-up 1 came back successful
    /// under 'topup_1' while its stored provider reference was "not found".
    /// A real failure needs the provider to say so with nothing still pending;
    /// "never heard of it" only becomes a failure once it has lasted past the
    /// expiry window.
    private function verdictFor(CoinTopUpRequests $topup, $gateway): string
    {
        $sawFailed = false;
        $sawPending = false;
        $allNotFound = true;

        foreach (TopUpReconciler::referencesFor($topup) as $reference) {
            $result = $gateway->verify($reference);
            if ($result['status'] === GatewayResult::COMPLETED) {
                return GatewayResult::COMPLETED;
            }
            if ($result['status'] === GatewayResult::FAILED) {
                $sawFailed = true;
                $allNotFound = false;
                continue;
            }
            if (empty($result['not_found'])) {
                $sawPending = true;
                $allNotFound = false;
            }
        }

        if ($sawFailed && !$sawPending) {
            return GatewayResult::FAILED;
        }
        $expired = $topup->created_at !== null
            && $topup->created_at->lte(now()->subHours((int) $this->option('expire-hours')));
        if ($allNotFound && $expired) {
            return GatewayResult::FAILED;
        }
        return GatewayResult::PROCESSING;
    }
}
