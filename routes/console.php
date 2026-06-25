<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\VolkOilSpray;
use App\Models\Tractor;
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
    VolkOilSpray::where('end_dt', '<', today())
        ->chunkById(100, function ($volkOilSprays) {
            foreach ($volkOilSprays as $spray) {
                CalculateColdRequirementJob::dispatch($spray);
            }
        });
})->name('dispatch-cold-requirement-jobs')
    ->daily()
    ->withoutOverlapping(60);

Schedule::call(function () {
    CalculateFrostbiteRiskJob::dispatch();
})->name('dispatch-frostbite-risk-job')
    ->daily()
    ->withoutOverlapping(30);

Schedule::call(function () {
    $today = Carbon::today();

    Tractor::chunkById(100, function ($tractors) use ($today) {
        foreach ($tractors as $tractor) {
            CalculateGpsMetricsJob::dispatch($tractor, $today)->delay(now()->addSeconds(5));
        }
    });
})->name('dispatch-daily-gps-metrics-jobs')
    ->dailyAt('23:00:00')
    ->withoutOverlapping(120);

Schedule::command('tractor:check-service-alerts')
    ->daily()
    ->withoutOverlapping(60);

Schedule::command('telescope:prune --hours=24')
    ->daily()
    ->withoutOverlapping();
