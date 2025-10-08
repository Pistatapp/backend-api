<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\TractorTask;
use App\Traits\TractorWorkingTime;
use Carbon\Carbon;
use App\Services\ChunkedDatabaseOperations;

class ReportProcessingService
{
    use TractorWorkingTime;

    private float $totalTraveledDistance = 0;
    private int $totalMovingTime = 0;     // seconds
    private int $totalStoppedTime = 0;    // seconds
    private int $stoppageCount = 0;       // number of stored stopped reports
    private int $maxSpeed = 0;
    private array $points = [];
    private $latestStoredReport;          // GpsReport|null
    private array|null $previousRawReport = null; // last raw report processed (array from parser)
    private CacheService $cacheService;

    // Stoppage accumulation properties
    private array|null $pendingStoppageReport = null; // first stoppage report in current segment
    private int $accumulatedStoppageTime = 0; // accumulated time for current stoppage segment

    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private ?TractorTask $currentTask = null,
        private ?array $taskZone = null,
    ) {
        $this->tractor = $device->tractor;
        $this->cacheService = new CacheService($device);
        $this->latestStoredReport = $this->cacheService->getLatestStoredReport();
        $this->previousRawReport = $this->cacheService->getPreviousReport();
    }

    /**
     * Main entry: process all incoming reports sequentially.
     * Now processes data for both task-specific and daily scopes.
     * Keeps logic intentionally linear & easy to read.
     */
    public function process(): array
    {
        // Process reports for task-specific scope (if task exists)
        $taskData = null;
        if ($this->currentTask && $this->taskZone) {
            $taskData = $this->processReportsForScope(true);
        }

        // Process reports for daily scope (always)
        $dailyData = $this->processReportsForScope(false);

        return [
            'taskData' => $taskData,
            'dailyData' => $dailyData,
            'latestStoredReport' => $this->latestStoredReport
        ];
    }

    /**
     * Process reports for a specific scope (task-specific or daily).
     *
     * @param bool $isTaskScope Whether to process for task scope or daily scope
     * @return array|null Processed data for the scope
     */
    private function processReportsForScope(bool $isTaskScope): ?array
    {
        // Reset metrics for this scope
        $this->resetMetrics();

        foreach ($this->reports as $report) {
            $this->handleReportForScope($report, $isTaskScope);
        }

        // Finalize any pending stoppage at the end of processing
        $this->finalizePendingStoppage();

        return [
            'totalTraveledDistance' => $this->totalTraveledDistance,
            'totalMovingTime' => $this->totalMovingTime,
            'totalStoppedTime' => $this->totalStoppedTime,
            'stoppageCount' => $this->stoppageCount,
            'maxSpeed' => $this->maxSpeed,
            'points' => $this->points,
        ];
    }

    /**
     * Reset metrics for processing a new scope.
     */
    private function resetMetrics(): void
    {
        $this->totalTraveledDistance = 0;
        $this->totalMovingTime = 0;
        $this->totalStoppedTime = 0;
        $this->stoppageCount = 0;
        $this->maxSpeed = 0;
        $this->points = [];
        $this->pendingStoppageReport = null;
        $this->accumulatedStoppageTime = 0;
    }

    /**
     * Handle a single report for a specific scope.
     *
     * @param array $report The GPS report to process
     * @param bool $isTaskScope Whether this is for task scope or daily scope
     */
    private function handleReportForScope(array $report, bool $isTaskScope): void
    {
        $diffs = $this->computeDiffsForScope($report, $isTaskScope);
        if ($diffs) {
            $this->applyMetrics($report, $diffs['time'], $diffs['distance']);
            // Only update max speed for reports that are counted (inside scope)
            $this->maxSpeed = max($this->maxSpeed, (int)$report['speed']);
        }
        [$persist, $addPoint] = $this->decidePersistence($report);
        $this->recordPointAndPersist($report, $persist, $addPoint);
        $this->finalizeReport($report);
    }

    /**
     * Handle a single report (legacy method for backward compatibility).
     *
     * Rules:
     *  - Time/distance always computed between consecutive raw reports if both countable.
     *  - Moving time increases when previous was moving; stopped time when previous was stopped.
     *  - Stoppage reports are accumulated and only persisted if total duration > 60 seconds.
     *  - We only persist: first report, every moving report, and first stoppage report of segments > 60s.
     *  - Points mirror that: always first, all moving, first stoppage of qualifying segments.
     */
    private function handleReport(array $report): void
    {
        $diffs = $this->computeDiffs($report);
        if ($diffs) {
            $this->applyMetrics($report, $diffs['time'], $diffs['distance']);
            // Only update max speed for reports that are counted (inside zone)
            $this->maxSpeed = max($this->maxSpeed, (int)$report['speed']);
        }
        [$persist, $addPoint] = $this->decidePersistence($report);
        $this->recordPointAndPersist($report, $persist, $addPoint);
        $this->finalizeReport($report);
    }

    /**
     * Compute time & distance deltas for a specific scope, returning null if previous missing or should not count.
     *
     * @param array $report Current report
     * @param bool $isTaskScope Whether this is for task scope or daily scope
     * @return array|null
     */
    private function computeDiffsForScope(array $report, bool $isTaskScope): ?array
    {
        if (!$this->previousRawReport) {
            return null;
        }
        $timeDiff = $this->previousRawReport['date_time']->diffInSeconds($report['date_time']);
        if ($timeDiff < 0) {
            return null; // ignore out-of-order
        }
        // Only compute diffs if both current and previous reports are within scope
        if (!$this->shouldCountReportForScope($report, $isTaskScope) ||
            !$this->shouldCountReportForScope($this->previousRawReport, $isTaskScope)) {
            return null; // outside scope
        }
        $distanceDiff = calculate_distance($this->previousRawReport['coordinate'], $report['coordinate']);
        return ['time' => $timeDiff, 'distance' => $distanceDiff];
    }

    /**
     * Compute time & distance deltas, returning null if previous missing or should not count.
     */
    private function computeDiffs(array $report): ?array
    {
        if (!$this->previousRawReport) {
            return null;
        }
        $timeDiff = $this->previousRawReport['date_time']->diffInSeconds($report['date_time']);
        if ($timeDiff < 0) {
            return null; // ignore out-of-order
        }
        // Only compute diffs if both current and previous reports are within working/task zone scope
        if (!$this->shouldCountReport($report) || !$this->shouldCountReport($this->previousRawReport)) {
            return null; // outside working/task zone scope
        }
        $distanceDiff = calculate_distance($this->previousRawReport['coordinate'], $report['coordinate']);
        return ['time' => $timeDiff, 'distance' => $distanceDiff];
    }

    /**
     * Apply movement / stoppage metrics based on previous & current states.
     */
    private function applyMetrics(array $report, int $timeDiff, float $distanceDiff): void
    {
        $prevStopped = $this->previousRawReport['is_stopped'];
        $isStopped = $report['is_stopped'];

        if ($prevStopped && $isStopped) { // stopped -> stopped
            $this->totalStoppedTime += $timeDiff;
            $this->accumulatedStoppageTime += $timeDiff;
        } elseif ($prevStopped && !$isStopped) { // stopped -> moving
            $this->totalMovingTime += $timeDiff;
            $this->totalTraveledDistance += $distanceDiff;
            $this->finalizePendingStoppage();
        } elseif (!$prevStopped && $isStopped) { // moving -> stopped
            $this->totalStoppedTime += $timeDiff;
            $this->totalTraveledDistance += $distanceDiff;
            $this->startStoppageAccumulation($report, $timeDiff);
        } else { // moving -> moving
            $this->totalMovingTime += $timeDiff;
            $this->totalTraveledDistance += $distanceDiff;
        }
    }

    /**
     * Decide if current raw report should be persisted & included in points.
     */
    private function decidePersistence(array $report): array
    {
        $persist = false;
        $addPoint = false;

        if ($this->latestStoredReport === null) {
            $persist = true; // first ever
            $addPoint = true;
        } elseif (!$report['is_stopped']) {
            $persist = true; // every moving report
            $addPoint = true;
        } else {
            // For stoppage reports, check if this might be needed for start/end point detection
            $mightBeNeededForDetection = $this->mightBeNeededForStartEndDetection($report);

            // Also check if this might be needed for on time detection
            $mightBeNeededForOnTimeDetection = $this->mightBeNeededForOnTimeDetection($report);

            if ($mightBeNeededForDetection || $mightBeNeededForOnTimeDetection) {
                $persist = true; // Save for detection purposes
                $addPoint = true;
            } else {
                // For other stoppage reports, we don't persist immediately
                // They will be persisted later if accumulated time > 60 seconds
                $persist = false;
                $addPoint = false;
            }
        }

        return [$persist, $addPoint];
    }

    /**
     * Persist and/or add to points list.
     */
    private function recordPointAndPersist(array $report, bool $persist, bool $addPoint): void
    {
        if ($addPoint) {
            $this->points[] = $report;
        }

        if ($persist) {
            // If this is a stoppage report being saved for detection purposes,
            // ensure it has the proper stoppage_time
            if ($report['is_stopped'] && $this->pendingStoppageReport && $this->accumulatedStoppageTime > 0) {
                $report['stoppage_time'] = $this->accumulatedStoppageTime;
            }
            $this->saveReport($report);
        }
    }

    /**
     * Final bookkeeping after processing raw report.
     */
    private function finalizeReport(array $report): void
    {
        $this->previousRawReport = $report;
        $this->cacheService->setPreviousReport($report);
    }

    /**
     * Check if a report is within the current task's time window.
     * Handles tasks that cross midnight.
     */
    private function isWithinTaskTime(array $report): bool
    {
        if (!$this->currentTask) {
            return false;
        }

        $dateTime = $report['date_time'];

        // Extract start/end time strings (HH:MM)
        $startTime = is_string($this->currentTask->start_time)
            ? $this->currentTask->start_time
            : $this->currentTask->start_time->format('H:i');

        $endTime = is_string($this->currentTask->end_time)
            ? $this->currentTask->end_time
            : $this->currentTask->end_time->format('H:i');

        // Task date may be Carbon|string
        $taskDate = $this->currentTask->date instanceof \Carbon\Carbon
            ? $this->currentTask->date->toDateString()
            : $this->currentTask->date;

        $taskStart = \Carbon\Carbon::parse($taskDate . ' ' . $startTime);
        $taskEnd = \Carbon\Carbon::parse($taskDate . ' ' . $endTime);

        // Handle tasks that cross midnight
        if ($taskEnd->lt($taskStart)) {
            $taskEnd->addDay();
        }

        return $dateTime->gte($taskStart) && $dateTime->lte($taskEnd);
    }

    /**
     * Check if a report should be counted for a specific scope.
     *
     * @param array $report The GPS report
     * @param bool $isTaskScope Whether this is for task scope or daily scope
     * @return bool
     */
    private function shouldCountReportForScope(array $report, bool $isTaskScope): bool
    {
        if ($isTaskScope) {
            // For task scope, ensure report time falls within the task's time window
            if (!$this->isWithinTaskTime($report)) {
                return false;
            }

            // Then ensure report is within task zone
            if ($this->currentTask && $this->taskZone) {
                return is_point_in_polygon($report['coordinate'], $this->taskZone);
            }

            return false; // No task/zone, don't count for task scope
        }

        // For daily scope, keep using tractor working hours
        if (!$this->isWithinWorkingHours($report)) {
            return false;
        }

        return true;
    }

    private function shouldCountReport(array $report): bool
    {
        // Always check working hours first for start/end time detection
        if (!$this->isWithinWorkingHours($report)) {
            return false;
        }

        // If there's a task, also check if report is within task zone
        if ($this->currentTask && $this->taskZone) {
            return is_point_in_polygon($report['coordinate'], $this->taskZone);
        }

        return true;
    }

    /**
     * Check if a stoppage report might be needed for start/end point detection.
     * This includes reports that could be part of transition sequences.
     */
    private function mightBeNeededForStartEndDetection(array $report): bool
    {
        // Only save the first stoppage report after movement for potential end point detection
        // This is a minimal approach that preserves detection capability while maintaining
        // the 60-second accumulation logic for other stoppage reports
        if ($this->previousRawReport && !$this->previousRawReport['is_stopped'] && $report['is_stopped']) {
            return true;
        }

        return false;
    }

    /**
     * Check if a report might be needed for on time detection.
     * This includes reports that could be part of status transition sequences.
     */
    private function mightBeNeededForOnTimeDetection(array $report): bool
    {
        // Only save reports that could be part of status transition from 0 to 1
        // This is more specific to avoid saving too many reports
        if ($this->previousRawReport && $this->previousRawReport['status'] === 0 && $report['status'] === 1) {
            return true;
        }

        return false;
    }

    /**
     * Get the tractor's start work time for today.
     *
     * @return Carbon
     */
    private function getTractorStartWorkTimeForToday(): Carbon
    {
        return now()->setTimeFromTimeString($this->tractor->start_work_time);
    }

    /**
     * Start accumulating stoppage time for a new stoppage segment.
     */
    private function startStoppageAccumulation(array $report, int $timeDiff): void
    {
        $this->pendingStoppageReport = $report;
        $this->accumulatedStoppageTime = $timeDiff;
    }

    /**
     * Finalize pending stoppage when movement is detected or processing ends.
     * Only save the stoppage report if accumulated time exceeds 60 seconds.
     */
    private function finalizePendingStoppage(): void
    {
        if ($this->pendingStoppageReport && $this->accumulatedStoppageTime > 60) {
            // Save the first stoppage report with accumulated time
            $reportData = $this->pendingStoppageReport;
            $reportData['stoppage_time'] = $this->accumulatedStoppageTime;

            // Save the report
            $this->saveReport($reportData);

            // Add to points and increment stoppage count
            $this->points[] = $reportData;
            $this->stoppageCount++;
        }

        // Reset accumulation state
        $this->pendingStoppageReport = null;
        $this->accumulatedStoppageTime = 0;
    }

    private function saveReport(array $data): void
    {
        $report = $this->device->reports()->create($data);
        $this->latestStoredReport = $report;
        $this->cacheService->setLatestStoredReport($report);

        // Always attempt start/end point detection regardless of task presence
        // This ensures detection works even when tractor has a task
        $this->detectStartEndPoints($report);
    }


    /**
     * Batch insert reports to database for improved performance.
     * This method collects all reports that need to be persisted and inserts them in a single operation.
     */
    private function batchInsertReports(): void
    {
        $reportsToInsert = [];

        foreach ($this->reports as $report) {
            if ($this->shouldPersistReport($report)) {
                $reportsToInsert[] = array_merge($report, [
                    'gps_device_id' => $this->device->id,
                ]);
            }
        }

        if (!empty($reportsToInsert)) {
            // Use ChunkedDatabaseOperations for memory-efficient batch inserts
            $chunkedOps = new ChunkedDatabaseOperations();
            $chunkedOps->batchInsert('gps_reports', $reportsToInsert);

            // Update the latest stored report reference
            if (!empty($reportsToInsert)) {
                $latestReportData = end($reportsToInsert);
                $this->latestStoredReport = $this->device->reports()
                    ->where('date_time', $latestReportData['date_time'])
                    ->where('coordinate', $latestReportData['coordinate'])
                    ->first();
                $this->cacheService->setLatestStoredReport($this->latestStoredReport);
            }
        }
    }

    /**
     * Determine if a report should be persisted to the database.
     * This is a simplified version of the persistence logic for batch processing.
     *
     * @param array $report
     * @return bool
     */
    private function shouldPersistReport(array $report): bool
    {
        if ($this->latestStoredReport === null) {
            return true; // first ever report
        }

        if (!$report['is_stopped']) {
            return true; // every moving report
        }

        // For stoppage reports, check if this might be needed for detection
        $mightBeNeededForDetection = $this->mightBeNeededForStartEndDetection($report);
        $mightBeNeededForOnTimeDetection = $this->mightBeNeededForOnTimeDetection($report);

        return $mightBeNeededForDetection || $mightBeNeededForOnTimeDetection;
    }
}
