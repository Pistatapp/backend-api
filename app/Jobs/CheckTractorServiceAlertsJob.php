<?php

namespace App\Jobs;

use App\Models\Tractor;
use App\Models\Warning;
use App\Notifications\TractorServiceAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class CheckTractorServiceAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $warnings = Warning::where('key', 'tractor_periodic_service')
            ->where('enabled', true)
            ->get()
            ->keyBy('farm_id');

        if ($warnings->isEmpty()) {
            return;
        }

        Tractor::whereIn('farm_id', $warnings->keys())
            ->with('farm.users')
            ->chunk(100, function ($tractors) use ($warnings): void {
                foreach ($tractors as $tractor) {
                    $warning = $warnings->get($tractor->farm_id);
                    if (!$warning) {
                        continue;
                    }

                    $intervalHours = (float) ($warning->parameters['interval_hours'] ?? 0);
                    $intervalKm = (float) ($warning->parameters['interval_km'] ?? 0);
                    if ($intervalHours <= 0 && $intervalKm <= 0) {
                        continue;
                    }

                    $serviceStart = $tractor->last_service_at ?? $tractor->created_at ?? Carbon::now();

                    if ($tractor->last_service_notified_at && $tractor->last_service_notified_at->gte($serviceStart)) {
                        continue;
                    }

                    $metrics = $tractor->gpsMetricsCalculations()
                        ->whereDate('date', '>=', $serviceStart->toDateString())
                        ->selectRaw('COALESCE(SUM(work_duration), 0) as work_duration_seconds')
                        ->selectRaw('COALESCE(SUM(traveled_distance), 0) as traveled_distance')
                        ->first();

                    $workedHours = ((float) ($metrics->work_duration_seconds ?? 0)) / 3600;
                    $traveledKm = (float) ($metrics->traveled_distance ?? 0);

                    $reachedHours = $intervalHours > 0 && $workedHours >= $intervalHours;
                    $reachedKm = $intervalKm > 0 && $traveledKm >= $intervalKm;

                    if (!$reachedHours && !$reachedKm) {
                        continue;
                    }

                    Notification::send(
                        $tractor->farm->users,
                        new TractorServiceAlertNotification($tractor, $intervalHours, $intervalKm)
                    );

                    $tractor->forceFill([
                        'last_service_notified_at' => now(),
                    ])->save();
                }
            });
    }
}
