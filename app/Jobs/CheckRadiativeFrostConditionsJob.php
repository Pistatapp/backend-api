<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Notifications\RadiativeFrostNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class CheckRadiativeFrostConditionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all farms with enabled radiative frost warnings
        $farms = Farm::whereHas('warnings', function ($query) {
            $query->where('key', 'radiative_frost_warning')
                  ->where('enabled', true);
        })->with(['warnings' => function ($query) {
            $query->where('key', 'radiative_frost_warning');
        }])->get();

        foreach ($farms as $farm) {
            $location = $farm->center;

            // Get weather forecast for tomorrow (we need day+1 for radiative frost)
            $data = weather_api()->forecast($location, 2);

            // Get tomorrow's forecast
            $day = $data['forecast']['forecastday'][1];
            $maxDewPoint = collect($day['hour'])->max('dewpoint_c');
            $avgTemp = $day['day']['avgtemp_c'];

            // Calculate radiative frost risk using the formula
            $temp1 = (0.18 * $avgTemp) + (0.083 * $maxDewPoint) - 2.33;
            $temp2 = (0.21 * $avgTemp) + 2.3;

            // If either calculation shows temperature at or below 0, there's a risk
            if ($temp1 <= 0 || $temp2 <= 0) {
                // Send notification to all users associated with the farm
                Notification::send(
                    $farm->users,
                    new RadiativeFrostNotification(
                        $avgTemp,
                        $maxDewPoint,
                        jdate($day['date'])->format('Y/m/d')
                    )
                );
            }
        }
    }
}
