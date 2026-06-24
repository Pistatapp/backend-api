<?php

namespace App\Console;

use App\Jobs\CheckFrostConditionsJob;
use App\Jobs\CheckOilSprayConditionsJob;
use App\Jobs\CheckRadiativeFrostConditionsJob;
use App\Jobs\GenerateDailyAttendanceSummaryJob;
use App\Jobs\CloseAttendanceSessionsJob;
use App\Jobs\CheckPestDegreeDayConditionsJob;
use App\Jobs\CheckCropTypeDegreeDayConditionsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Carbon\Carbon;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('app:change-farm-plan-status')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('app:change-irrigation-status')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('tractor:check-stoppage-warnings')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->onOneServer();

        $schedule->command('tractor:check-activity-status')
            ->everyMinute()
            ->withoutOverlapping(5)
            ->onOneServer();

        $schedule->command('tractor:update-ended-tasks')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer();

        $schedule->command('tractors:check-inactivity')
            ->dailyAt('08:00')
            ->withoutOverlapping(30)
            ->onOneServer();

        $schedule->job(new CheckFrostConditionsJob)
            ->dailyAt('06:00')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->job(new CheckRadiativeFrostConditionsJob)
            ->dailyAt('18:00')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->job(new CheckOilSprayConditionsJob)
            ->dailyAt('00:00')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->job(new CheckPestDegreeDayConditionsJob)
            ->dailyAt('05:00')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->job(new CheckCropTypeDegreeDayConditionsJob)
            ->dailyAt('05:30')
            ->withoutOverlapping(60)
            ->onOneServer();

        $schedule->job(new GenerateDailyAttendanceSummaryJob(Carbon::yesterday()))
            ->dailyAt('00:15')
            ->withoutOverlapping(120)
            ->onOneServer();

        $schedule->job(new CloseAttendanceSessionsJob)
            ->hourly()
            ->withoutOverlapping(30)
            ->onOneServer();
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
