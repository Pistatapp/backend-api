<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\Warning;
use App\Notifications\TractorStoppageNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class TractorStoppageWarningService
{
    /**
     * Check for tractor stoppage warnings and send notifications if needed
     */
    public function checkAndNotify(): void
    {
        // Get the current user's working environment
        $user = Auth::user();
        if (!$user) {
            return;
        }

        $workingEnvironment = $user->preferences['working_environment'] ?? null;
        if (!$workingEnvironment) {
            return;
        }

        // Get warning configuration for the current working environment
        $warning = Warning::where('farm_id', $workingEnvironment)
            ->where('key', 'tractor_stoppage')
            ->where('enabled', true)
            ->first();

        if (!$warning) {
            return;
        }

        $threshold = (int) ($warning->parameters['hours'] ?? 1) * 3600; // Convert hours to seconds

        // Get all tractors with their daily reports for this farm
        $tractors = Tractor::with(['farm.users', 'farm.admins', 'gpsMetricsCalculations' => function ($query) {
            $query->whereDate('date', today());
        }])->where('farm_id', $workingEnvironment)->get();

        foreach ($tractors as $tractor) {
            $dailyReport = $tractor->gpsMetricsCalculations->first();

            if (!$dailyReport || $dailyReport->stoppage_duration < $threshold) {
                continue;
            }

            $farm = $tractor->farm;
            $adminIds = $farm->admins->modelKeys();
            $recipients = $farm->users
                ->reject(fn ($user) => in_array($user->id, $adminIds, true))
                ->values();

            if ($recipients->isEmpty()) {
                continue;
            }

            // Keep dashboard tractor warnings away from farm admins. Admins
            // only receive the final task report notification.
            Notification::send(
                $recipients,
                new TractorStoppageNotification(
                    $tractor,
                    $dailyReport->stoppage_duration,
                    (int) ($warning->parameters['hours'] ?? 1),
                    today()
                )
            );
        }
    }
}
