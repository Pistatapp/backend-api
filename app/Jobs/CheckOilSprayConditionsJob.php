<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Notifications\OilSprayNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class CheckOilSprayConditionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all farms with enabled oil spray warnings
        $farms = Farm::whereHas('warnings', function ($query) {
            $query->where('key', 'oil_spray_warning')
                  ->where('enabled', true);
        })->with(['warnings' => function ($query) {
            $query->where('key', 'oil_spray_warning');
        }])->get();

        foreach ($farms as $farm) {
            $warning = $farm->warnings->first();

            if (!$warning) {
                continue;
            }

            $hours = (int) ($warning->parameters['hours'] ?? 0);
            $startDate = $warning->parameters['start_date'] ?? null;
            $endDate = $warning->parameters['end_date'] ?? null;

            if (!$hours || !$startDate || !$endDate) {
                continue;
            }

            // Only run on end_date
            $today = Carbon::now()->format('Y-m-d');
            if ($today !== $endDate) {
                continue;
            }

            // Get weather history for the specified period using center string directly
            $data = weather_api()->history($farm->center, $startDate, $endDate);

            // Calculate cold requirement using method 1 with default temperature range (0-7°C)
            $chillingHours = $this->calculateColdRequirementMethod1($data, 0, 7);

            // If chilling requirement is less than specified hours, send notification
            if ($chillingHours < $hours) {
                Notification::send(
                    $farm->users,
                    new OilSprayNotification(
                        jdate($startDate)->format('Y/m/d'),
                        jdate($endDate)->format('Y/m/d'),
                        $hours,
                        $chillingHours
                    )
                );
            }
        }
    }

    /**
     * Calculate the cold requirement using method 1.
     */
    private function calculateColdRequirementMethod1(array $data, int $minTemp, int $maxTemp): int
    {
        return collect($data['forecast']['forecastday'])->sum(function ($day) use ($minTemp, $maxTemp) {
            return collect($day['hour'])->filter(function ($hour) use ($minTemp, $maxTemp) {
                $temp = $hour['temp_c'];
                return $temp >= $minTemp && $temp <= $maxTemp;
            })->count();
        });
    }
}
