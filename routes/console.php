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
    $volkOilSprays = VolkOilSpray::where('end_dt', '<', today())->get();

    foreach ($volkOilSprays as $spray) {
        CalculateColdRequirementJob::dispatch($spray);
    }
})->daily();

Schedule::call(function () {
    CalculateFrostbiteRiskJob::dispatch();
})->daily();

Schedule::call(function () {
    $today = Carbon::today();

    // Use chunking to handle large datasets and avoid memory issues
    Tractor::chunk(100, function ($tractors) use ($today) {
        foreach ($tractors as $tractor) {
            // Calculate metrics for the entire day
            // The job will check if metrics already exist
            CalculateGpsMetricsJob::dispatch($tractor, $today)->delay(now()->addSeconds(5));
        }
    });
})->dailyAt('23:00:00');
