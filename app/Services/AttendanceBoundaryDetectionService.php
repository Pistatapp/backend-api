<?php

namespace App\Services;

use App\Models\User;
use App\Models\AttendanceSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Events\AttendanceUpdated;

class AttendanceBoundaryDetectionService
{
    public function __construct(
        private AttendanceService $attendanceService,
    ) {}

    /**
     * Process GPS point and check if user is in orchard boundary
     *
     * @param User $user
     * @param array $coordinate ['lat' => float, 'lng' => float, 'altitude' => float]
     * @param Carbon $dateTime
     * @return void
     */
    public function processGpsPoint(User $user, array $coordinate, Carbon $dateTime): void
    {
        $attendanceTracking = $user->attendanceTracking;

        if (! $attendanceTracking || ! $attendanceTracking->enabled) {
            return;
        }

        $farm = $attendanceTracking->farm;

        if (! $farm || ! $farm->coordinates) {
            Log::warning('Farm or coordinates not found for user', [
                'user_id' => $user->id,
                'farm_id' => $farm?->id,
            ]);
            return;
        }

        $point = [$coordinate['lng'], $coordinate['lat']];
        $isInBoundary = is_point_in_polygon($point, $farm->coordinates);

        $session = $this->attendanceService->getOrCreateSession($user, $dateTime->copy()->startOfDay());
        $session->refresh();

        $lastGpsPointTime = $session->updated_at ?? $session->entry_time ?? $dateTime;

        if ($isInBoundary) {
            $this->handleEntry($user, $session, $dateTime);
            $session->refresh();
        } else {
            if ($session->entry_time) {
                $lastInZoneTime = $session->entry_time;
                if ($lastInZoneTime->lt($dateTime)) {
                    $this->handleExit($user, $session, $dateTime, $lastInZoneTime);
                    $session->refresh();
                }
            }
        }

        $this->updateTimeTracking($session, $isInBoundary, $dateTime);

        event(new AttendanceUpdated($user, $session));
    }

    private function handleEntry(User $user, AttendanceSession $session, Carbon $dateTime): void
    {
        $shouldUpdate = ! $session->entry_time;

        if (! $shouldUpdate && $session->entry_time) {
            $entryTimeStr = $session->entry_time->format('H:i:s');
            $shouldUpdate = $entryTimeStr === '00:00:00';
        }

        if ($shouldUpdate) {
            $session->entry_time = $dateTime;
            $session->status = 'in_progress';
            $session->save();
            $session->refresh();
        }
    }

    private function handleExit(User $user, AttendanceSession $session, Carbon $dateTime, Carbon $lastInZoneTime): void
    {
        if ($session->status === 'in_progress') {
            $minutesOut = abs($dateTime->diffInMinutes($lastInZoneTime));

            if ($minutesOut >= 30) {
                $session->exit_time = $dateTime;
                $session->status = 'completed';
                $session->save();
            }
        }
    }

    private function updateTimeTracking(AttendanceSession $session, bool $isInBoundary, Carbon $dateTime): void
    {
        $lastGpsTime = $session->updated_at ?? $session->entry_time ?? $dateTime;
        $minutesDiff = $dateTime->diffInMinutes($lastGpsTime);

        if ($minutesDiff > 0) {
            if ($isInBoundary) {
                $session->increment('total_in_zone_duration', $minutesDiff);
            } else {
                $session->increment('total_out_zone_duration', $minutesDiff);
            }
        }

        $session->timestamps = false;
        $session->update(['updated_at' => $dateTime]);
        $session->timestamps = true;
    }
}
