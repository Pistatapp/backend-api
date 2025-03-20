<?php

namespace App\Traits;

use App\Models\GpsReport;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

trait TractorWorkingTime
{
    /**
     * Set the start and end working times based on the GPS report.
     *
     * @param  \App\Models\GpsReport  $report
     * @return void
     */
    public function setWorkingTimes(GpsReport $report): void
    {
        $this->setStartWorkingTime($report);
        $this->setEndWorkingTime($report);
    }

    /**
     * Determine and set the start working time.
     *
     * @param  \App\Models\GpsReport  $report
     * @return void
     */
    private function setStartWorkingTime(GpsReport $report): void
    {
        $cacheKey = 'start_working_time_' . $this->tractor->id;

        // Check if the start time is already set
        if (!Cache::has($cacheKey)) {
            // Ensure the tractor is moving and within the expected working hours
            if (!$report->is_stopped && $this->isWithinWorkingHours($report)) {
                // Check if the tractor has been moving consistently for a threshold duration
                if ($this->hasConsistentMovement($report, 'start')) {
                    $report->update(['is_starting_point' => true]);
                    Cache::put($cacheKey, $report->date_time, now()->endOfDay());
                }
            }
        }
    }

    /**
     * Determine and set the end working time.
     *
     * @param  \App\Models\GpsReport  $report
     * @return void
     */
    private function setEndWorkingTime(GpsReport $report): void
    {
        $cacheKey = 'ending_time_' . $this->tractor->id;

        // Check if the end time is already set
        if (!Cache::has($cacheKey)) {
            // Ensure the tractor is stopped and within the expected working hours
            if ($report->is_stopped && $this->isWithinWorkingHours($report)) {
                // Check if the tractor has been stopped consistently for a threshold duration
                if ($this->hasConsistentMovement($report, 'end')) {
                    $report->update(['is_ending_point' => true]);
                    Cache::put($cacheKey, $report->date_time, now()->endOfDay());
                }
            }
        }
    }

    /**
     * Check if the report is within the tractor's working hours.
     *
     * @param  \App\Models\GpsReport|array  $report
     * @return bool
     */
    private function isWithinWorkingHours(GpsReport|array $report): bool
    {
        $dateTime = is_array($report) ? Carbon::parse($report['date_time']) : $report->date_time;
        return $dateTime->gte($this->tractor->start_work_time)
            && $dateTime->lte($this->tractor->end_work_time);
    }

    /**
     * Check if the tractor has consistent movement or stoppage for a threshold duration.
     *
     * @param  \App\Models\GpsReport  $report
     * @param  string  $type  'start' or 'end'
     * @return bool
     */
    private function hasConsistentMovement(GpsReport $report, string $type): bool
    {
        $thresholdSeconds = 300; // 5 minutes threshold
        $cacheKey = ($type === 'start' ? 'consistent_movement_' : 'consistent_stoppage_') . $this->tractor->id;

        // Retrieve the last consistent time from the cache
        $lastConsistentTime = Cache::get($cacheKey);

        if (!$lastConsistentTime) {
            // Initialize the consistent time if not set
            Cache::put($cacheKey, $report->date_time, now()->endOfDay());
            return false;
        }

        // Calculate the time difference between the current report and the last consistent time
        $timeDifference = $report->date_time->diffInSeconds($lastConsistentTime);

        if ($type === 'start' && !$report->is_stopped && $timeDifference >= $thresholdSeconds) {
            // Update the cache for consistent movement
            Cache::put($cacheKey, $report->date_time, now()->endOfDay());
            return true;
        }

        if ($type === 'end' && $report->is_stopped && $timeDifference >= $thresholdSeconds) {
            // Update the cache for consistent stoppage
            Cache::put($cacheKey, $report->date_time, now()->endOfDay());
            return true;
        }

        // Reset the cache if the condition is not met
        Cache::put($cacheKey, $report->date_time, now()->endOfDay());
        return false;
    }
}
