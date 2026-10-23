<?php

namespace App\Console\Commands;

use App\Services\Verification\BlueTick;
use Illuminate\Console\Command;

// Ends paid blue ticks whose period is over (BlueTick). Admin-given ticks
// never expire and store subscriptions end through RevenueCat's webhook.
class ExpireVerifications extends Command
{
    protected $signature = 'verification:expire';

    protected $description = 'End paid blue ticks whose period is over';

    public function handle(): int
    {
        $expired = BlueTick::expireDue();
        if ($expired > 0) {
            $this->info("Expired {$expired} paid blue tick(s).");
        }
        return self::SUCCESS;
    }
}
