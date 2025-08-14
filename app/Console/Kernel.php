<?php

namespace App\Console;

use App\Jobs\CheckFrostConditionsJob;
use App\Jobs\CheckOilSprayConditionsJob;
use App\Jobs\CheckRadiativeFrostConditionsJob;
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

        // Check for tractor activity status every minute
        $schedule->command('tractor:check-activity-status')->everyMinute();

        // Check for inactive tractors daily at 8 AM
        $schedule->command('tractors:check-inactivity')
            ->dailyAt('08:00')
            ->appendOutputTo(storage_path('logs/tractor-inactivity-check.log'));

        // Check for frost conditions daily at 6 AM
        $schedule->job(new CheckFrostConditionsJob)->dailyAt('06:00');

        // Check for radiative frost conditions daily at 6 PM
        $schedule->job(new CheckRadiativeFrostConditionsJob)->dailyAt('18:00');

        // Check for oil spray conditions daily at midnight
        $schedule->job(new CheckOilSprayConditionsJob)->dailyAt('00:00');

        // Check for pest degree day conditions daily at 05:00 AM
        $schedule->job(CheckFrostConditionsJob::class)->dailyAt('05:00');

        // Check for crop type degree day conditions daily at 05:30 AM
        $schedule->job(CheckFrostConditionsJob::class)->dailyAt('05:30');
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
