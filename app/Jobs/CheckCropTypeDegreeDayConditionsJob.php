<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Farm;
use App\Notifications\CropTypeDegreeDayNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

class CheckCropTypeDegreeDayConditionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Process warnings for each farm
        Farm::with('warnings')->each(function ($farm) {
            $warning = $farm->warnings()
                ->where('key', 'crop_type_degree_day_warning')
                ->where('enabled', true)
                ->first();

            if (!$warning) {
                return;
            }

            $degreeDays = (float) ($warning->parameters['degree_days'] ?? 0);
            $startDate = $warning->parameters['start_date'] ?? null;
            $endDate = $warning->parameters['end_date'] ?? null;
            $cropType = $warning->parameters['crop_type'] ?? null;

            if (!$degreeDays || !$startDate || !$endDate || !$cropType) {
                return;
            }

            // Only run on end_date
            $today = Carbon::now()->format('Y-m-d');
            if ($today !== $endDate) {
                return;
            }

            // Get weather history for the specified period
            $data = weather_api()->history($farm->center, $startDate, $endDate);

            // Calculate degree days for the period
            $actualDegreeDays = $this->calculateDegreeDays($data);

            // If actual degree days is less than required, send notification
            if ($actualDegreeDays < $degreeDays) {
                Notification::send(
                    $farm->users,
                    new CropTypeDegreeDayNotification(
                        $cropType,
                        jdate($startDate)->format('Y/m/d'),
                        jdate($endDate)->format('Y/m/d'),
                        $degreeDays,
                        $actualDegreeDays
                    )
                );
            }
        });
    }

    /**
     * Calculate degree days using average daily temperature method.
     */
    private function calculateDegreeDays(array $data): float
    {
        return collect($data['forecast']['forecastday'])->sum(function ($day) {
            $avgTemp = $day['day']['avgtemp_c'];
            // Base temperature is typically 10°C for many crops
            $baseTemp = 10.0;

            // If average temperature is below base temperature, no degree days accumulated
            if ($avgTemp <= $baseTemp) {
                return 0;
            }

            return $avgTemp - $baseTemp;
        });
    }
}
