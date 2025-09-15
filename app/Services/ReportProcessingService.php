<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\TractorTask;
use App\Traits\TractorWorkingTime;
use Carbon\Carbon;

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
        private ?array $taskArea = null,
    ) {
        $this->tractor = $device->tractor;
        $this->cacheService = new CacheService($device);
        $this->latestStoredReport = $this->cacheService->getLatestStoredReport();
        $this->previousRawReport = $this->cacheService->getPreviousReport();
    }

    /**
     * Main entry: process all incoming reports sequentially.
     * Keeps logic intentionally linear & easy to read.
     */
    public function process(): array
    {
        foreach ($this->reports as $report) {
            $this->handleReport($report);
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
            'latestStoredReport' => $this->latestStoredReport
        ];
    }

    /**
     * Handle a single report.
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
        $this->maxSpeed = max($this->maxSpeed, (int)$report['speed']);
        $diffs = $this->computeDiffs($report);
        if ($diffs) {
            $this->applyMetrics($report, $diffs['time'], $diffs['distance']);
        }
        [$persist, $addPoint] = $this->decidePersistence($report);
        $this->recordPointAndPersist($report, $persist, $addPoint);
        $this->finalizeReport($report);
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
        // Only compute diffs if both current and previous reports are within working/task scope
        if (!$this->shouldCountReport($report) || !$this->shouldCountReport($this->previousRawReport)) {
            return null; // outside working/task scope
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

    private function shouldCountReport(array $report): bool
    {
        // Always check working hours first for start/end time detection
        if (!$this->isWithinWorkingHours($report)) {
            return false;
        }

        // If there's a task, also check if report is within task area
        if ($this->currentTask && $this->taskArea) {
            return is_point_in_polygon($report['coordinate'], $this->taskArea);
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
}
