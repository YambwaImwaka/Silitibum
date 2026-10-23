<?php

namespace App\Console\Commands;

use App\Models\Constants;
use App\Models\GlobalSettings;
use App\Models\RedeemRequests;
use App\Services\Payments\PaymentGatewayFactory;
use App\Services\Wallet\Withdrawals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

// The payout-side twin of topups:reconcile: webhooks are the fast path, not a
// guarantee (Lenco sometimes never delivers one), so automated payouts still
// Processing are re-checked with the provider on a schedule.
//
// It never refunds on its own judgement. Only the provider saying "failed"
// refunds (Withdrawals::fail); a payout the provider has not confirmed after a
// day is logged for an admin to check in the provider's dashboard — refunding
// a transfer that actually went out would pay the creator twice.
class ReconcilePayouts extends Command
{
    protected $signature = 'payouts:reconcile
                            {--grace=5 : Minutes to let the webhook land first}
                            {--days=30 : Ignore payouts older than this}
                            {--expire-hours=24 : Refund a payout the provider has never heard of after this long}';

    protected $description = 'Re-verify automated payouts still in Processing with their provider.';

    public function handle(): int
    {
        $settings = GlobalSettings::first();
        $open = RedeemRequests::where('status', Constants::withdrawalProcessing)
            ->whereNotNull('provider')
            ->where('provider', '!=', 'manual')
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('grace')))
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')
            ->get();

        foreach ($open as $redeem) {
            $gateway = PaymentGatewayFactory::forProvider($redeem->provider, $settings);
            if ($gateway === null) {
                $this->warn("withdrawal {$redeem->id}: provider '{$redeem->provider}' is not configured");
                continue;
            }
            $result = $gateway->verifyPayout($redeem->provider_reference ?: $redeem->request_number);
            $stale = $redeem->updated_at->lte(now()->subHours((int) $this->option('expire-hours')));

            // The provider has no transfer under this attempt's reference at
            // all (the request never reached it: DNS, connect error). Each
            // attempt has its own reference, so none can appear later — the
            // money never left. Without this nothing could close the request:
            // Reject is refused while an automated payout is Processing and
            // Retry needs Failed, so the creator's earnings stayed frozen.
            if ($result['status'] === 'processing' && !empty($result['not_found']) && $stale) {
                Withdrawals::fail($redeem, 'The payout never reached the payment provider.');
                $this->line("withdrawal {$redeem->id}: never reached {$redeem->provider} — failed and refunded");
                continue;
            }

            Withdrawals::applyProviderStatus($redeem, $result);
            $this->line("withdrawal {$redeem->id}: {$result['status']}");

            if ($result['status'] === 'processing' && $stale) {
                Log::warning("payouts:reconcile: withdrawal {$redeem->id} ({$redeem->provider_reference}) still unconfirmed by {$redeem->provider} after a day — check it in the provider dashboard.");
            }
        }

        $this->info('Checked ' . $open->count() . ' payout(s).');
        return self::SUCCESS;
    }
}
