<?php

namespace App\Services;

use App\Models\GpsDevice;
use App\Traits\TractorWorkingTime;

class ReportProcessingService
{
    use TractorWorkingTime;

    private $totalTraveledDistance = 0;
    private $totalMovingTime = 0;
    private $totalStoppedTime = 0;
    private $stoppageCount = 0;
    private $maxSpeed;
    private $points = [];
    private $latestStoredReport;
    private $lastProcessedReport = null;

    /**
     * Create a new report processing service instance.
     *
     * @param GpsDevice $device
     * @param array $reports
     * @param mixed $currentTask
     * @param mixed $taskArea
     * @param callable $isWithinWorkingHours
     * @param CacheService $cacheService
     */
    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private $currentTask,
        private $taskArea,
        private $isWithinWorkingHours,
        private CacheService $cacheService
    ) {
        $this->maxSpeed = 0;
        $this->tractor = $this->device->tractor;
        $this->latestStoredReport = $this->cacheService->getLatestStoredReport();
    }

    /**
     * Process the reports and calculate statistics.
     *
     * @return array The processed report data including total traveled distance, moving time, stopped time, etc.
     */
    public function process(): array
    {
        $previousReport = $this->cacheService->getPreviousReport();

        foreach ($this->reports as $report) {
            if (is_null($previousReport)) {
                $this->processFirstReport($report);
            } else {
                $this->processSubsequentReports($previousReport, $report);
            }

            $previousReport = $report;
            $this->cacheService->setPreviousReport($previousReport);
            $this->lastProcessedReport = $report;
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
     * Processes the first report by saving it, updating the max speed,
     * and checking if it should be counted based on task or working hours.
     *
     * @param array $report The report data.
     *
     * @return void
     */
    private function processFirstReport(array $report): void
    {
        $this->saveReport($report);
        $this->points[] = $report;
        $this->maxSpeed = $report['speed'];

        // Check if report should be counted based on task or working hours
        if ($this->shouldCountReport($report)) {
            if ($report['is_stopped']) {
                $this->stoppageCount += 1;
            }
        }
    }

    /**
     * Processes subsequent reports by calculating the time difference and distance difference
     * between the previous and current report, and updating the statistics accordingly.
     *
     * @param array $previousReport The previous report data.
     * @param array $report The current report data.
     *
     * @return void
     */
    private function processSubsequentReports(array $previousReport, array $report): void
    {
        $this->maxSpeed = max($this->maxSpeed, $report['speed']);
        $distanceDiff = calculate_distance($previousReport['coordinate'], $report['coordinate']);
        $timeDiff = $previousReport['date_time']->diffInSeconds($report['date_time']);

        $transitionHandler = $this->getTransitionHandler($previousReport['is_stopped'], $report['is_stopped']);
        $transitionHandler($report, $timeDiff, $distanceDiff);
    }

    /**
     * Returns the appropriate transition handler based on the previous and current report states.
     *
     * @param bool $wasStopped Indicates if the previous report was stopped.
     * @param bool $isStopped  Indicates if the current report is stopped.
     *
     * @return callable The transition handler function.
     */
    private function getTransitionHandler(bool $wasStopped, bool $isStopped): callable
    {
        return match (true) {
            $wasStopped && $isStopped => fn($report, $timeDiff, $distanceDiff) => $this->handleStoppedToStopped($report, $timeDiff, $distanceDiff),
            $wasStopped && !$isStopped => fn($report, $timeDiff, $distanceDiff) => $this->handleStoppedToMoving($report, $timeDiff, $distanceDiff),
            !$wasStopped && $isStopped => fn($report, $timeDiff, $distanceDiff) => $this->handleMovingToStopped($report, $timeDiff, $distanceDiff),
            default => fn($report, $timeDiff, $distanceDiff) => $this->handleMovingToMoving($report, $timeDiff, $distanceDiff),
        };
    }

    /**
     * Handles the transition from stopped to stopped state.
     *
     * @param array $report The report data.
     * @param int   $timeDiff The difference in time (in seconds) to add to the total stopped time.
     * @param float $distanceDiff The distance difference (in kilometers/meters) to add to the total traveled distance.
     *
     * @return void
     */
    private function handleStoppedToStopped(array $report, int $timeDiff, float $distanceDiff): void
    {
        $incrementStoppage = $timeDiff > 120 ? true : false;
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true, $incrementStoppage);

        $this->latestStoredReport->incrementStoppageTime($timeDiff);
    }

    /**
     * Handles the transition from stopped to moving state.
     *
     * @param array $report The report data.
     * @param int   $timeDiff The difference in time (in seconds) to add to the total moving time.
     * @param float $distanceDiff The distance difference (in kilometers/meters) to add to the total traveled distance.
     *
     * @return void
     */
    private function handleStoppedToMoving(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);
        $this->latestStoredReport->incrementStoppageTime($timeDiff);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    /**
     * Handles the transition from moving to stopped state.
     *
     * @param array $report The report data.
     * @param int   $timeDiff The difference in time (in seconds) to add to the total stopped time.
     * @param float $distanceDiff The distance difference (in kilometers/meters) to add to the total traveled distance.
     *
     * @return void
     */
    private function handleMovingToStopped(array $report, int $timeDiff, float $distanceDiff): void
    {
        $incrementStoppage = $timeDiff > 60 ? true : false;
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, false, $incrementStoppage);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    /**
     * Handles the transition from moving to moving state.
     *
     * @param array $report The report data.
     * @param int   $timeDiff The difference in time (in seconds) to add to the total moving time.
     * @param float $distanceDiff The distance difference (in kilometers/meters) to add to the total traveled distance.
     *
     * @return void
     */
    private function handleMovingToMoving(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    /**
     * Determines if the report should be counted based on the current task and working hours.
     *
     * @param array $report The report data.
     *
     * @return bool True if the report should be counted, false otherwise.
     */
    private function shouldCountReport(array $report): bool
    {
        // If there's a current task, only check if the point is within the task area
        if ($this->currentTask && $this->taskArea) {
            return is_point_in_polygon($report['coordinate'], $this->taskArea);
        }

        // If no task is defined, check if the report is within working hours
        return !$this->currentTask && ($this->isWithinWorkingHours)($report);
    }

    /**
     * Increments the timing and traveled distance based on the report data.
     *
     * @param array $report The report data.
     * @param int   $timeDiff The difference in time (in seconds) to add to the total stopped or moving time.
     * @param float $distanceDiff The distance difference (in kilometers/meters) to add to the total traveled distance if moving.
     * @param bool  $stopped Indicates whether the entity is currently stopped.
     * @param bool  $incrementStoppage Whether to increment the stoppage count.
     *
     * @return void
     */
    private function incrementTimingAndTraveledDistance(array $report, int $timeDiff, float $distanceDiff, bool $stopped = false, bool $incrementStoppage = false): void
    {
        if ($this->shouldCountReport($report)) {
            $this->updateTimingAndDistance($timeDiff, $distanceDiff, $stopped, $incrementStoppage);
        }
    }

    /**
     * Updates the timing and distance statistics based on the current state.
     *
     * @param int   $timeDiff           The difference in time (in seconds) to add to the total stopped or moving time.
     * @param float $distanceDiff       The distance difference (in kilometers/meters) to add to the total traveled distance if moving.
     * @param bool  $stopped            Indicates whether the entity is currently stopped.
     * @param bool  $incrementStoppage  Whether to increment the stoppage count.
     *
     * @return void
     */
    private function updateTimingAndDistance(int $timeDiff, float $distanceDiff, bool $stopped, bool $incrementStoppage): void
    {
        if ($incrementStoppage) {
            $this->stoppageCount += 1;
        }

        $this->{$stopped ? 'totalStoppedTime' : 'totalMovingTime'} += $timeDiff;
        $this->totalTraveledDistance += ($stopped ? 0 : $distanceDiff);
    }

    /**
     * Saves a new report for the current device using the provided data.
     *
     * This method creates a new report record associated with the device,
     * updates the latest stored report reference, caches the latest report,
     * and sets the working times for the report.
     *
     * @param array $data The data to be used for creating the report.
     *
     * @return void
     */
    private function saveReport(array $data): void
    {
        $report = $this->device->reports()->create($data);
        $this->latestStoredReport = $report;
        $this->cacheService->setLatestStoredReport($report);
        $this->setWorkingTimes($report);
    }
}
