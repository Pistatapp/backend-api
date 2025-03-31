<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Models\Warning;
use App\Notifications\FrostNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class CheckFrostConditionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all farms with enabled frost warnings
        $farms = Farm::whereHas('warnings', function ($query) {
            $query->where('key', 'frost_warning')
                  ->where('enabled', true);
        })->with(['users', 'warnings' => function ($query) {
            $query->where('key', 'frost_warning');
        }])->get();

        foreach ($farms as $farm) {
            $warning = $farm->warnings->first();
            $days = (int) ($warning->parameters['days'] ?? 3);

            $location = $farm->center;

            // Get weather forecast for the next few days
            $data = weather_api()->forecast($location, $days + 1);

            $daysWithRisk = collect($data['forecast']['forecastday'])
                ->filter(function ($day) {
                    return $day['day']['mintemp_c'] <= 0;
                })
                ->map(function ($day) {
                    return [
                        'temperature' => (float) $day['day']['mintemp_c'],
                        'date' => jdate($day['date'])->format('Y/m/d')
                    ];
                });

            if ($daysWithRisk->isNotEmpty()) {
                $firstRisk = $daysWithRisk->first();

                // Send notification to all users associated with the farm
                Notification::send(
                    $farm->users,
                    new FrostNotification(
                        $firstRisk['temperature'],
                        $firstRisk['date'],
                        $days
                    )
                );
            }
        }
    }
}
