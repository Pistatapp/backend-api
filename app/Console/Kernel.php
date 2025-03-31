<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('app:change-farm-plan-status')->everyMinute();
        $schedule->command('app:change-irrigation-status')->everyMinute();

        // Check and update tractor task status every minute
        $schedule->command('tractor:update-task-status')->everyMinute();

        // Check for tractor stoppage warnings every 5 minutes
        $schedule->command('tractor:check-stoppage-warnings')->everyFiveMinutes();

        // Check for inactive tractors daily at 8 AM
        $schedule->command('tractors:check-inactivity')
            ->dailyAt('08:00')
            ->appendOutputTo(storage_path('logs/tractor-inactivity-check.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
