<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\TractorTask;
use App\Traits\TractorWorkingTime;
use Illuminate\Support\Facades\Log;

class ReportProcessingService
{
    use TractorWorkingTime;

    private float $totalTraveledDistance = 0;
    private int $totalMovingTime = 0;     // seconds
    private int $totalStoppedTime = 0;    // seconds
    private int $stoppageCount = 0;       // number of moving->stopped transitions
    private float $maxSpeed = 0;
    private array $points = [];
    private $latestStoredReport;          // GpsReport|null
    private array|null $previousRawReport = null; // last raw report processed (array from parser)
    private CacheService $cacheService;

    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private ?TractorTask $currentTask = null,
        private ?array $taskArea = null,
    ) {
        $this->tractor = $device->tractor;
        $this->cacheService = new CacheService($device);
        $this->latestStoredReport = $this->cacheService->getLatestStoredReport();
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
     *  - Stoppage count increments only on moving -> stopped transition.
     *  - We only persist: first report, every moving report, and first stopped report of a stoppage segment.
     *  - Points mirror that: always first, all moving, first stopped of a segment.
     */
    private function handleReport(array $report): void
    {
        $report = $this->normalizeReport($report);
        $diffs = $this->computeDiffs($report);
        Log::info("Diffs for {$this->tractor->id}", $diffs);
        if ($diffs) {
            $this->applyMetrics($report, $diffs['time'], $diffs['distance']);
        }
        [$persist, $addPoint] = $this->decidePersistence($report);
        $this->recordPointAndPersist($report, $persist, $addPoint);
        $this->finalizeReport($report);
    }

    /**
     * Add derived flags & update speed extremes.
     */
    private function normalizeReport(array $report): array
    {
        $report['is_stopped'] = ($report['speed'] == 0);
        $this->maxSpeed = max($this->maxSpeed, (float)$report['speed']);
        return $report;
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
        if (!($this->shouldCountReport($report) && $this->shouldCountReport($this->previousRawReport))) {
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
        $prevStopped = $this->previousRawReport['speed'] == 0;
        $isStopped = $report['is_stopped'];

        if ($prevStopped && $isStopped) { // stopped -> stopped
            $this->totalStoppedTime += $timeDiff;
            if ($this->latestStoredReport?->is_stopped) {
                $this->latestStoredReport->incrementStoppageTime($timeDiff);
            }
        } elseif ($prevStopped && !$isStopped) { // stopped -> moving
            $this->totalMovingTime += $timeDiff;
            $this->totalTraveledDistance += $distanceDiff;
        } elseif (!$prevStopped && $isStopped) { // moving -> stopped
            $this->stoppageCount++;
            $this->totalStoppedTime += $timeDiff;
            $this->totalTraveledDistance += $distanceDiff;
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
        if ($this->latestStoredReport === null) {
            $persist = true; // first ever
        } elseif (!$report['is_stopped']) {
            $persist = true; // every moving report
        } else {
            $persist = !($this->latestStoredReport->is_stopped ?? false); // only first stopped in segment
        }
        return [$persist, $persist]; // same rule for points
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
        if ($this->currentTask && $this->taskArea) {
            return is_point_in_polygon($report['coordinate'], $this->taskArea);
        }
        return $this->isWithinWorkingHours($report);
    }

    private function saveReport(array $data): void
    {
        $report = $this->device->reports()->create($data);
        $this->latestStoredReport = $report;
        $this->cacheService->setLatestStoredReport($report);
        $this->setWorkingTimes($report);
    }
}
