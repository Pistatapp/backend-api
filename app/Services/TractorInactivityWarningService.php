<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\Warning;
use App\Notifications\TractorInactivityNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class TractorInactivityWarningService
{
    /**
     * Check for tractor inactivity warnings and send notifications if needed
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
            ->where('key', 'tractor_inactivity')
            ->where('enabled', true)
            ->first();

        if (!$warning) {
            return;
        }

        $threshold = (int) ($warning->parameters['days'] ?? 1) * 86400; // Convert days to seconds

        // Get all tractors for this farm
        $tractors = Tractor::where('farm_id', $workingEnvironment)->get();

        foreach ($tractors as $tractor) {
            // Get the last activity time from the tractor
            $lastActivity = $tractor->last_activity;
            if (!$lastActivity) {
                continue;
            }

            // Calculate inactivity duration
            $inactivityDuration = $lastActivity->diffInSeconds(now());

            if ($inactivityDuration < $threshold) {
                continue;
            }

            // Send notification to all users associated with the tractor's farm
            $users = $tractor->farm->users;

            Notification::send(
                $users,
                new TractorInactivityNotification(
                    $tractor,
                    $lastActivity,
                    (int) ($warning->parameters['days'] ?? 1),
                    today()
                )
            );
        }
    }
}
