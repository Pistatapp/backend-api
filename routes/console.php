<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\VolkOilSpray;
use App\Jobs\CalculateColdRequirementJob;
use App\Jobs\CalculateFrostbiteRiskJob;

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
