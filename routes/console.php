<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\VolkOilSpray;
use App\Models\Tractor;
use App\Models\GpsMetricsCalculation;
use App\Jobs\CalculateColdRequirementJob;
use App\Jobs\CalculateFrostbiteRiskJob;
use App\Jobs\CalculateGpsMetricsJob;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    VolkOilSpray::query()
        ->whereDate('end_dt', today()->subDay())
        ->whereNull('cold_requirement_checked_at')
        ->chunkById(100, function ($sprays) {
            foreach ($sprays as $spray) {
                CalculateColdRequirementJob::dispatch($spray);
            }
        });
})->name('volk-oil-spray-cold-requirement')
    ->dailyAt('00:00')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::call(function () {
    CalculateFrostbiteRiskJob::dispatch();
})->name('frostbite-risk-daily')
    ->dailyAt('00:05')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::call(function () {
    $today = Carbon::today();

    Tractor::chunkById(100, function ($tractors) use ($today) {
        foreach ($tractors as $tractor) {
            if (GpsMetricsCalculation::query()
                ->where('tractor_id', $tractor->id)
                ->whereDate('date', $today)
                ->whereNull('tractor_task_id')
                ->exists()) {
                continue;
            }

            CalculateGpsMetricsJob::dispatch($tractor, $today)
                ->delay(now()->addSeconds($tractor->id % 300));
        }
    });
})->name('daily-gps-metrics')
    ->dailyAt('23:00')
    ->withoutOverlapping(180)
    ->onOneServer();

Schedule::command('tractor:check-service-alerts')
    ->dailyAt('00:10')
    ->withoutOverlapping(120)
    ->onOneServer();

Schedule::command('telescope:prune --hours=24')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->onOneServer();
