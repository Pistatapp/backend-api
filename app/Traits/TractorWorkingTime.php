<?php

namespace App\Traits;

use App\Models\GpsReport;
use App\Models\Tractor;
use Illuminate\Support\Facades\Cache;

trait TractorWorkingTime
{
    /**
     * The tractor associated with this device.
     *
     * @var Tractor|null
     */
    protected $tractor;

    private const SPEED_THRESHOLD = 2; // km/h
    private const WINDOW_SIZE = 3; // Reports to analyze in sliding window
    private const CACHE_TTL = 60; // 1 hour in minutes
    private const SHORT_CACHE_TTL = 5; // 5 minutes

    /**
     * Detect the start and end points of the tractor's working time
     *
     * @param GpsReport $report
     * @return void
     */
    private function detectStartEndPoints(GpsReport $report): void
    {
        $dateString = $report->date_time->toDateString();
        $tractorId = $this->tractor->id;

        // Use a single cache key for all points data
        $cacheKey = "tractor_points_{$tractorId}_{$dateString}";
        $points = Cache::get($cacheKey, ['start' => false, 'end' => false]);

        // If we already have both points, exit early
        if ($points['start'] && $points['end']) {
            return;
        }

        // Check if we need to load cache from database
        if (!$points['start']) {
            $points['start'] = $this->hasStartPointForToday($report);
        }

        if (!$points['end']) {
            $points['end'] = $this->hasEndPointForToday($report);
        }

        // Exit if both points are already set
        if ($points['start'] && $points['end']) {
            Cache::put($cacheKey, $points, now()->addMinutes(self::CACHE_TTL));
            return;
        }

        $reportsCacheKey = "tractor_reports_{$tractorId}_{$dateString}";
        $surroundingReports = Cache::remember($reportsCacheKey, now()->addMinutes(self::SHORT_CACHE_TTL), function () use ($report) {
            return $this->device->reports()
                ->whereDate('date_time', $report->date_time->toDateString())
                ->orderBy('date_time')
                ->select(['id', 'date_time', 'speed']) // Only select needed fields
                ->get();
        });

        // Add current report if not in collection
        if (!$surroundingReports->contains('id', $report->id)) {
            $surroundingReports->push($report);
            $surroundingReports = $surroundingReports->sortBy('date_time')->values();
            Cache::put($reportsCacheKey, $surroundingReports, now()->addMinutes(self::SHORT_CACHE_TTL));
        }

        $currentIndex = $surroundingReports->search(fn($item) => $item->id === $report->id);

        if ($currentIndex === false) {
            return;
        }

        $updated = false;

        // Check for start point
        if (!$points['start'] && $currentIndex >= 1) {
            $updated = $this->detectStartPoint($surroundingReports, $points) || $updated;
        }

        // Check for end point
        if (!$points['end'] && $currentIndex >= self::WINDOW_SIZE - 1) {
            $updated = $this->detectEndPoint($surroundingReports, $currentIndex, $report, $points) || $updated;
        }

        // Update cache if needed
        if ($updated) {
            Cache::put($cacheKey, $points, now()->addMinutes(self::CACHE_TTL));
        }
    }

    /**
     * Detect a start point in the reports
     *
     * @param \Illuminate\Support\Collection $reports
     * @param array &$points
     * @return bool
     */
    private function detectStartPoint($reports, array &$points): bool
    {
        $count = $reports->count();

        if ($count < self::WINDOW_SIZE) {
            return false;
        }
        $reports = $reports->values(); // Reset keys for index access

        // Get the tractor's start work time for today
        $startWorkTime = $this->getTractorStartWorkTimeForToday();

        for ($i = 1; $i < $count; $i++) {
            $prevReport = $reports[$i - 1];
            $currReport = $reports[$i];

            if ($prevReport->speed < self::SPEED_THRESHOLD &&
                $currReport->speed >= self::SPEED_THRESHOLD) {

                // Check if the movement start time is after the tractor's start_work_time
                if ($currReport->date_time->lt($startWorkTime)) {
                    continue; // Skip this potential start point as it's before work hours
                }

                // Check for sustained movement
                $sustainedMovement = true;
                for ($j = 1; $j < self::WINDOW_SIZE && ($i + $j) < $count; $j++) {
                    if ($reports[$i + $j]->speed < self::SPEED_THRESHOLD) {
                        $sustainedMovement = false;
                        break;
                    }
                }

                if ($sustainedMovement) {
                    $currReport->update(['is_starting_point' => true]);
                    $points['start'] = true;
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Detect an end point in the reports
     *
     * @param \Illuminate\Support\Collection $reports
     * @param int $currentIndex
     * @param GpsReport $report
     * @param array &$points
     * @return bool
     */
    private function detectEndPoint($reports, $currentIndex, $report, array &$points): bool
    {
        $window = $reports->slice($currentIndex - self::WINDOW_SIZE + 1, self::WINDOW_SIZE)->values();

        // Guard: Ensure window has enough elements
        if ($window->count() < self::WINDOW_SIZE) {
            return false;
        }

        $isEndPoint = true;
        for ($i = 0; $i < self::WINDOW_SIZE - 1; $i++) {
            if ($window[$i]->speed < self::SPEED_THRESHOLD) {
                $isEndPoint = false;
                break;
            }
        }

        $isEndPoint = $isEndPoint && $window->last()->speed < self::SPEED_THRESHOLD;

        if ($isEndPoint) {
            $report->update(['is_ending_point' => true]);
            $points['end'] = true;
            return true;
        }

        return false;
    }

    /**
     * Check if the report has a starting point for today.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasStartPointForToday(GpsReport $report): bool
    {
        $dateString = $report->date_time->toDateString();
        $cacheKey = "start_point_{$this->tractor->id}_{$dateString}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($dateString) {
            return $this->device->reports()
                ->whereDate('date_time', $dateString)
                ->where('is_starting_point', true)
                ->exists();
        });
    }

    /**
     * Check if there is an ending point for today.
     *
     * @param GpsReport $report
     * @return bool
     */
    private function hasEndPointForToday(GpsReport $report): bool
    {
        $dateString = $report->date_time->toDateString();
        $cacheKey = "end_point_{$this->tractor->id}_{$dateString}";

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL), function () use ($dateString) {
            return $this->device->reports()
                ->whereDate('date_time', $dateString)
                ->where('is_ending_point', true)
                ->exists();
        });
    }

    /**
     * Check if the report's date_time is within working hours.
     *
     * @param GpsReport|array $report
     * @return bool
     */
    private function isWithinWorkingHours(GpsReport|array $report): bool
    {
        $dateTime = $report['date_time'];
        $cacheKey = "tractor_working_hours_{$this->tractor->id}_{$dateTime->toDateString()}";

        $workingHours = Cache::remember($cacheKey, now()->endOfDay(), function () use ($dateTime) {
            return [
                'start' => $dateTime->copy()->setTimeFromTimeString($this->tractor->start_work_time),
                'end' => $dateTime->copy()->setTimeFromTimeString($this->tractor->end_work_time)
            ];
        });

        // Handle case where end time is before start time (crosses midnight)
        if ($workingHours['end']->lt($workingHours['start'])) {
            return $dateTime->gte($workingHours['start']) || $dateTime->lte($workingHours['end']);
        }

        return $dateTime->gte($workingHours['start']) && $dateTime->lte($workingHours['end']);
    }

    /**
     * Get the tractor's start work time for today.
     *
     * @return \Carbon\Carbon
     */
    private function getTractorStartWorkTimeForToday(): \Carbon\Carbon
    {
        $cacheKey = "tractor_start_work_time_{$this->tractor->id}";

        return Cache::remember($cacheKey, now()->endOfDay(), function () {
            return now()->setTimeFromTimeString($this->tractor->start_work_time);
        });
    }
}
