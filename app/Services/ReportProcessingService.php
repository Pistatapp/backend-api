<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Models\GpsReport;
use App\Models\TractorTask;
use Illuminate\Support\Facades\Cache;

class ReportProcessingService
{
    private $tractor;

    // Metrics
    private float $totalTraveledDistance = 0;
    private int $totalMovingTime = 0;
    private int $totalStoppedTime = 0;
    private int $stoppageCount = 0;
    private int $ignoredStoppageCount = 0;
    private array $points = [];

    // State tracking (cached between batches)
    private ?array $previousReport = null;
    private bool $isCurrentlyStopped = false;
    private bool $isCurrentlyMoving = false;
    private ?int $stoppageStartTime = null; // timestamp
    private ?array $stoppageStartReport = null;
    private int $stoppageAccumulatedTime = 0;
    private int $stoppageAccumulatedTimeOn = 0;
    private int $stoppageAccumulatedTimeOff = 0;

    // Activation tracking
    private ?string $deviceOnTime = null;
    private ?string $firstMovementTime = null;

    private CacheService $cacheService;

    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private ?TractorTask $currentTask = null,
        private ?array $taskZone = null,
    ) {
        $this->tractor = $this->device->tractor;
        $this->cacheService = new CacheService($device);
        // Load cached state from previous batch
        $this->loadCachedState();
    }

    /**
     * Load cached state from previous batch processing
     */
    private function loadCachedState(): void
    {
        $dateKey = now()->toDateString();
        $cacheKey = "gps_processing_state_{$this->device->id}_{$dateKey}";

        $state = Cache::get($cacheKey, [
            'isCurrentlyStopped' => false,
            'isCurrentlyMoving' => false,
            'stoppageStartTime' => null,
            'stoppageStartReport' => null,
            'stoppageAccumulatedTime' => 0,
            'stoppageAccumulatedTimeOn' => 0,
            'stoppageAccumulatedTimeOff' => 0,
            'deviceOnTime' => null,
            'firstMovementTime' => null,
        ]);

        $this->isCurrentlyStopped = $state['isCurrentlyStopped'];
        $this->isCurrentlyMoving = $state['isCurrentlyMoving'];
        $this->stoppageStartTime = $state['stoppageStartTime'];
        $this->stoppageStartReport = $state['stoppageStartReport'];
        $this->stoppageAccumulatedTime = $state['stoppageAccumulatedTime'];
        $this->stoppageAccumulatedTimeOn = $state['stoppageAccumulatedTimeOn'];
        $this->stoppageAccumulatedTimeOff = $state['stoppageAccumulatedTimeOff'];
        $this->deviceOnTime = $state['deviceOnTime'];
        $this->firstMovementTime = $state['firstMovementTime'];

        // Load previous report
        $this->previousReport = $this->cacheService->getPreviousReport();
    }

    /**
     * Save current state to cache for next batch
     */
    private function saveCachedState(): void
    {
        $dateKey = now()->toDateString();
        $cacheKey = "gps_processing_state_{$this->device->id}_{$dateKey}";

        $state = [
            'isCurrentlyStopped' => $this->isCurrentlyStopped,
            'isCurrentlyMoving' => $this->isCurrentlyMoving,
            'stoppageStartTime' => $this->stoppageStartTime,
            'stoppageStartReport' => $this->stoppageStartReport,
            'stoppageAccumulatedTime' => $this->stoppageAccumulatedTime,
            'stoppageAccumulatedTimeOn' => $this->stoppageAccumulatedTimeOn,
            'stoppageAccumulatedTimeOff' => $this->stoppageAccumulatedTimeOff,
            'deviceOnTime' => $this->deviceOnTime,
            'firstMovementTime' => $this->firstMovementTime,
        ];

        Cache::put($cacheKey, $state, now()->endOfDay());
    }

    /**
     * Main entry: process all incoming reports using GpsDataAnalyzer algorithm
     * Optimized for batch processing with state caching
     */
    public function process(): array
    {
        foreach ($this->reports as $report) {
            // Skip reports outside working hours
            if (!$this->isWithinWorkingHours($report)) {
                continue;
            }

            $this->processReport($report);
        }

        // Save state for next batch
        $this->saveCachedState();

        return [
            'taskData' => null, // Task logic removed
            'dailyData' => [
                'totalTraveledDistance' => $this->totalTraveledDistance,
                'totalMovingTime' => $this->totalMovingTime,
                'totalStoppedTime' => $this->totalStoppedTime,
                'stoppageCount' => $this->stoppageCount,
                'points' => $this->points,
            ],
            'latestStoredReport' => $this->cacheService->getLatestStoredReport()
        ];
    }

    /**
     * Process a single report using GpsDataAnalyzer algorithm
     * Speed > 2 km/h = moving, speed <= 2 km/h = stopped
     * Stoppages < 60s = counted as movement
     */
    private function processReport(array $report): void
    {
        // Detect activation times (simple logic like GpsDataAnalyzer)
        if ($this->deviceOnTime === null && $report['status'] == 1) {
            $this->deviceOnTime = $report['date_time']->toDateTimeString();
            $this->detectAndMarkDeviceOnTime($report);
        }

        if ($this->firstMovementTime === null && $report['status'] == 1 && $report['speed'] > 2) {
            $this->firstMovementTime = $report['date_time']->toDateTimeString();
            $this->detectAndMarkFirstMovement($report);
        }

        // First report in batch
        if ($this->previousReport === null) {
            if ($report['speed'] <= 2) {
                $this->isCurrentlyStopped = true;
                $this->stoppageStartTime = $report['date_time']->timestamp;
                $this->stoppageStartReport = $report;
                $this->stoppageAccumulatedTime = 0;
                $this->stoppageAccumulatedTimeOn = 0;
                $this->stoppageAccumulatedTimeOff = 0;
            } else {
                $this->isCurrentlyMoving = true;
            }

            // Save first report
            $this->saveReport($report);
            $this->points[] = $report;
            $this->previousReport = $report;
            return;
        }

        $timeDiff = $report['date_time']->timestamp - $this->previousReport['date_time']->timestamp;
        $isStopped = $report['speed'] <= 2;
        $isMoving = $report['speed'] > 2;

        // Transition: Moving -> Stopped
        if ($isStopped && $this->isCurrentlyMoving) {
            // Calculate distance for transition point
            $distance = calculate_distance($this->previousReport['coordinate'], $report['coordinate']);
            $this->totalTraveledDistance += $distance;
            $this->totalMovingTime += $timeDiff;

            // Save this transition report (last movement point)
            $this->saveReport($report);
            $this->points[] = $report;

            // Start stoppage
            $this->isCurrentlyMoving = false;
            $this->isCurrentlyStopped = true;
            $this->stoppageStartTime = $report['date_time']->timestamp;
            $this->stoppageStartReport = $report;
            $this->stoppageAccumulatedTime = 0;
            $this->stoppageAccumulatedTimeOn = 0;
            $this->stoppageAccumulatedTimeOff = 0;
        }
        // Transition: Stopped -> Moving
        elseif ($isMoving && $this->isCurrentlyStopped) {
            // Accumulate stoppage time for this transition
            $this->stoppageAccumulatedTime += $timeDiff;
            if ($report['status'] == 1) {
                $this->stoppageAccumulatedTimeOn += $timeDiff;
            } else {
                $this->stoppageAccumulatedTimeOff += $timeDiff;
            }

            // Check if stoppage qualifies (>= 60s)
            if ($this->stoppageAccumulatedTime >= 60) {
                // Valid stoppage - update first stoppage report with accumulated time
                $this->stoppageStartReport['stoppage_time'] = $this->stoppageAccumulatedTime;
                $this->totalStoppedTime += $this->stoppageAccumulatedTime;
                $this->stoppageCount++;

                // Update the first stoppage report in DB if it was saved
                GpsReport::where('gps_device_id', $this->device->id)
                    ->where('date_time', $this->stoppageStartReport['date_time'])
                    ->update(['stoppage_time' => $this->stoppageAccumulatedTime]);
            } else {
                // Ignored stoppage - add to movement time
                $this->totalMovingTime += $this->stoppageAccumulatedTime;
                $this->ignoredStoppageCount++;
            }

            // Start movement
            $this->isCurrentlyStopped = false;
            $this->isCurrentlyMoving = true;

            // Save this moving report (first movement point)
            $this->saveReport($report);
            $this->points[] = $report;
        }
        // Continue moving
        elseif ($isMoving && $this->isCurrentlyMoving) {
            $distance = calculate_distance($this->previousReport['coordinate'], $report['coordinate']);
            $this->totalTraveledDistance += $distance;
            $this->totalMovingTime += $timeDiff;

            // Save moving report
            $this->saveReport($report);
            $this->points[] = $report;
        }
        // Continue stopped
        elseif ($isStopped && $this->isCurrentlyStopped) {
            // Accumulate stoppage time
            $this->stoppageAccumulatedTime += $timeDiff;
            if ($report['status'] == 1) {
                $this->stoppageAccumulatedTimeOn += $timeDiff;
            } else {
                $this->stoppageAccumulatedTimeOff += $timeDiff;
            }
            // Don't save consecutive stoppage points
        }

        // Update previous report and cache
        $this->previousReport = $report;
        $this->cacheService->setPreviousReport($report);
    }

    /**
     * Simplified device ON time detection (like GpsDataAnalyzer)
     * Marks the first report with status == 1 as on_time
     */
    private function detectAndMarkDeviceOnTime(array $report): void
    {
        $dateString = $report['date_time']->toDateString();
        $cacheKey = "on_time_detected_{$this->tractor->id}_{$dateString}";

        // Check if already detected today
        if (Cache::has($cacheKey)) {
            return;
        }

        // Check if already exists in DB
        $exists = $this->device->reports()
            ->whereDate('date_time', $dateString)
            ->whereNotNull('on_time')
            ->exists();

        if (!$exists) {
            // Mark this report with on_time
            $report['on_time'] = $report['date_time'];
            Cache::put($cacheKey, true, now()->endOfDay());
        }
    }

    /**
     * Simplified first movement detection (like GpsDataAnalyzer)
     * Marks first report with status == 1 AND speed > 2 as starting point
     */
    private function detectAndMarkFirstMovement(array $report): void
    {
        $dateString = $report['date_time']->toDateString();
        $cacheKey = "start_point_detected_{$this->tractor->id}_{$dateString}";

        // Check if already detected today
        if (Cache::has($cacheKey)) {
            return;
        }

        // Check if already exists in DB
        $exists = $this->device->reports()
            ->whereDate('date_time', $dateString)
            ->where('is_starting_point', true)
            ->exists();

        if (!$exists) {
            // Mark this report as starting point
            $report['is_starting_point'] = true;
            Cache::put($cacheKey, true, now()->endOfDay());
        }
    }

    /**
     * Save report to database
     */
    private function saveReport(array $reportData): void
    {
        $report = $this->device->reports()->create($reportData);
        $this->cacheService->setLatestStoredReport($report);
    }

    /**
     * Check if report is within working hours
     */
    private function isWithinWorkingHours(array $report): bool
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
}
