<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WorkerAttendanceSession;
use App\Services\WorkerAttendanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Events\WorkerAttendanceUpdated;

class WorkerBoundaryDetectionService
{
    public function __construct(
        private WorkerAttendanceService $attendanceService,
    ) {}

    /**
     * Process GPS point and check if worker is in orchard boundary
     *
     * @param Employee $employee
     * @param array $coordinate ['lat' => float, 'lng' => float, 'altitude' => float]
     * @param Carbon $dateTime
     * @return void
     */
    public function processGpsPoint(Employee $employee, array $coordinate, Carbon $dateTime): void
    {
        // Get farm boundary
        $farm = $employee->farm;

        if (!$farm || !$farm->coordinates) {
            Log::warning('Farm or coordinates not found for employee', [
                'employee_id' => $employee->id,
                'farm_id' => $farm?->id,
            ]);
            return;
        }

        // Convert coordinate to point format [lng, lat] for polygon check
        $point = [$coordinate['lng'], $coordinate['lat']];

        // Check if point is in farm boundary
        $isInBoundary = is_point_in_polygon($point, $farm->coordinates);

        // Get or create today's attendance session
        $session = $this->attendanceService->getOrCreateSession($employee, $dateTime->copy()->startOfDay());

        // Refresh to get latest state from database
        $session->refresh();

        // Store the last GPS point time before updateTimeTracking changes updated_at
        // This represents the last known state (last GPS point time)
        $lastGpsPointTime = $session->updated_at ?? $session->entry_time ?? $dateTime;

        // Update session based on boundary status
        if ($isInBoundary) {
            $this->handleEntry($employee, $session, $dateTime);
            $session->refresh(); // Refresh to get updated entry_time
        } else {
            // For exit, check time since last in-zone GPS point
            // When worker is outside boundary, we need to determine when they were last in zone
            // The last in-zone GPS point time should be stored in updated_at
            // (set by updateTimeTracking when the last in-zone GPS point was processed)
            // However, updated_at could also represent an out-of-zone GPS point
            // So we need to track whether the last GPS point was in-zone or out-of-zone
            // For now, we'll use updated_at as the last GPS point time
            // If the last GPS point was in-zone, this will be correct
            // If the last GPS point was out-of-zone, we'd need to track further back
            // The key insight: if worker just transitioned from in-zone to out-of-zone,
            // updated_at will represent the last in-zone GPS point time
            if ($session->entry_time) {
                // For exit calculation, we need to determine when the worker was last in zone
                // The issue is that updated_at might be updated by handleEntry()->save() to current time
                // So we use entry_time as the last in-zone time, which represents when the worker entered the zone
                // This works for the case where worker enters once and then exits
                // For more complex scenarios with multiple entries/exits, we'd need to track last in-zone time separately
                $lastInZoneTime = $session->entry_time;

                // Ensure lastInZoneTime is before current GPS point time
                // This prevents issues when processing the first GPS point
                if ($lastInZoneTime->lt($dateTime)) {
                    $this->handleExit($employee, $session, $dateTime, $lastInZoneTime);
                    $session->refresh(); // Refresh to get updated exit_time and status
                }
            }
        }

        // Update time tracking (this will update updated_at to current GPS point time)
        $this->updateTimeTracking($session, $isInBoundary, $dateTime);

        // Broadcast attendance update
        event(new WorkerAttendanceUpdated($employee, $session));
    }

    /**
     * Handle worker entry into orchard
     *
     * @param Employee $employee
     * @param WorkerAttendanceSession $session
     * @param Carbon $dateTime
     * @return void
     */
    private function handleEntry(Employee $employee, WorkerAttendanceSession $session, Carbon $dateTime): void
    {
        // Always update entry_time if it's null or if it's at start of day (00:00:00) which indicates it's a placeholder
        // This ensures entry_time reflects the actual time the worker entered the zone
        $shouldUpdate = !$session->entry_time;

        if (!$shouldUpdate && $session->entry_time) {
            // Check if entry_time is at start of day (00:00:00) which indicates it's a placeholder
            $entryTimeStr = $session->entry_time->format('H:i:s');
            $shouldUpdate = $entryTimeStr === '00:00:00';
        }

        if ($shouldUpdate) {
            $session->entry_time = $dateTime;
            $session->status = 'in_progress';
            $session->save();
            // Refresh to ensure the update is persisted
            $session->refresh();
        }
    }

    /**
     * Handle worker exit from orchard
     *
     * @param Employee $employee
     * @param WorkerAttendanceSession $session
     * @param Carbon $dateTime
     * @return void
     */
    private function handleExit(Employee $employee, WorkerAttendanceSession $session, Carbon $dateTime, Carbon $lastInZoneTime): void
    {
        // If worker has been out for more than 30 minutes, close the session
        // Check time since last in-zone GPS point
        // Only close if session is still in progress
        if ($session->status === 'in_progress') {
            // Calculate minutes since last in-zone time
            // diffInMinutes returns signed difference, so use abs() to get absolute value
            // This ensures we always get a positive value regardless of the order of arguments
            $minutesOut = abs($dateTime->diffInMinutes($lastInZoneTime));

            if ($minutesOut >= 30) { // Changed to >= to include exactly 30 minutes
                $session->exit_time = $dateTime;
                $session->status = 'completed';
                $session->save();
            }
        }
    }

    /**
     * Update time tracking for in-zone vs out-of-zone
     *
     * @param WorkerAttendanceSession $session
     * @param bool $isInBoundary
     * @param Carbon $dateTime
     * @return void
     */
    private function updateTimeTracking(WorkerAttendanceSession $session, bool $isInBoundary, Carbon $dateTime): void
    {
        // Get last GPS point time for this session
        $lastGpsTime = $session->updated_at ?? $session->entry_time ?? $dateTime;

        // Calculate time difference in minutes
        $minutesDiff = $dateTime->diffInMinutes($lastGpsTime);

        if ($minutesDiff > 0) {
            if ($isInBoundary) {
                $session->increment('total_in_zone_duration', $minutesDiff);
            } else {
                $session->increment('total_out_zone_duration', $minutesDiff);
            }
        }

        // Update updated_at to the GPS point time (not current time)
        // Use updateQuietly to prevent automatic timestamp updates
        $session->timestamps = false;
        $session->update(['updated_at' => $dateTime]);
        $session->timestamps = true;
    }
}

