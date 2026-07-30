<?php

namespace App\Console\Commands;

use App\Models\CoinTopUpRequests;
use App\Models\GlobalSettings;
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
class ReconcileTopUps extends Command
{
    protected $signature = 'topups:reconcile
                            {--grace=2 : Minutes to let the webhook land before chasing a top-up}
                            {--days=30 : Ignore top-ups older than this}
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

        $pending = CoinTopUpRequests::whereNotIn('status', ['completed', 'failed'])
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

            // A top-up is only declared failed when EVERY reference agrees.
            // One reference reporting "not found" while another reports the
            // payment succeeded is exactly what happened to top-up 1, and
            // treating that as a failure would bury a real payment.
            $verdict = 'processing';
            foreach (TopUpReconciler::referencesFor($topup) as $reference) {
                $result = $gateway->verify($reference);
                if ($result['status'] === 'completed') {
                    $verdict = 'completed';
                    break;
                }
                if ($result['status'] === 'failed' && $verdict !== 'failed') {
                    $verdict = 'failed';
                } elseif ($result['status'] === 'processing') {
                    $verdict = 'processing';
                    break;
                }
            }

            if ($dryRun) {
                $this->line("top-up {$topup->id} (user {$topup->user_id}, {$topup->coins} coins): {$topup->status} -> {$verdict} [dry run]");
                continue;
            }

            if (TopUpReconciler::apply($topup, $verdict)) {
                $credited++;
                $this->info("top-up {$topup->id}: credited {$topup->coins} coins to user {$topup->user_id} (webhook never landed)");
                Log::warning("ReconcileTopUps rescued top-up {$topup->id} for user {$topup->user_id} — the provider confirmed payment but no webhook was processed.");
            } elseif ($verdict === 'failed') {
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
}
