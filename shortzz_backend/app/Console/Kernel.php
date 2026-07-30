<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Catches top-ups the provider charged successfully but whose webhook
        // never reached us — otherwise the customer's money is gone and their
        // coins never arrive, silently and permanently (this is what stranded
        // top-up 1). withoutOverlapping() because verify() is a network call
        // per row and runs can outlast the interval on a slow provider.
        $schedule->command('topups:reconcile')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
