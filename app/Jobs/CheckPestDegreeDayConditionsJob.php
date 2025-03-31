<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Notifications\PestDegreeDayNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class CheckPestDegreeDayConditionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get all farms with enabled pest degree day warnings
        $farms = Farm::whereHas('warnings', function ($query) {
            $query->where('key', 'pest_degree_day_warning')
                  ->where('enabled', true);
        })->with(['warnings' => function ($query) {
            $query->where('key', 'pest_degree_day_warning');
        }])->get();

        foreach ($farms as $farm) {
            $warning = $farm->warnings->first();

            if (!$warning) {
                continue;
            }

            $degreeDays = (float) ($warning->parameters['degree_days'] ?? 0);
            $startDate = $warning->parameters['start_date'] ?? null;
            $endDate = $warning->parameters['end_date'] ?? null;
            $pest = $warning->parameters['pest'] ?? null;

            if (!$degreeDays || !$startDate || !$endDate || !$pest) {
                continue;
            }

            // Only run on end_date
            $today = Carbon::now()->format('Y-m-d');
            if ($today !== $endDate) {
                continue;
            }

            // Get weather history for the specified period
            $data = weather_api()->history($farm->center, $startDate, $endDate);

            // Calculate degree days for the period
            $actualDegreeDays = $this->calculateDegreeDays($data);

            // If actual degree days is less than required, send notification
            if ($actualDegreeDays < $degreeDays) {
                Notification::send(
                    $farm->users,
                    new PestDegreeDayNotification(
                        $pest,
                        jdate($startDate)->format('Y/m/d'),
                        jdate($endDate)->format('Y/m/d'),
                        $degreeDays,
                        $actualDegreeDays
                    )
                );
            }
        }
    }

    /**
     * Calculate degree days using average daily temperature method.
     */
    private function calculateDegreeDays(array $data): float
    {
        return collect($data['forecast']['forecastday'])->sum(function ($day) {
            $avgTemp = $day['day']['avgtemp_c'];
            // Base temperature is typically 10°C for many pests
            $baseTemp = 10.0;

            // If average temperature is below base temperature, no degree days accumulated
            if ($avgTemp <= $baseTemp) {
                return 0;
            }

            return $avgTemp - $baseTemp;
        });
    }
}
