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

    public function __construct(
        private GpsDevice $device,
        private array $reports,
        private $currentTask,
        private $taskArea,
        private $isWithinWorkingHours,
        private CacheService $cacheService
    ) {
        $this->maxSpeed = 0;
    }

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

    private function processSubsequentReports(array $previousReport, array $report): void
    {
        $this->maxSpeed = max($this->maxSpeed, $report['speed']);
        $distanceDiff = calculate_distance($previousReport['coordinate'], $report['coordinate']);
        $timeDiff = $previousReport['date_time']->diffInSeconds($report['date_time']);

        // Only process transitions if report should be counted
        $transitionHandler = $this->getTransitionHandler($previousReport['is_stopped'], $report['is_stopped']);
        $transitionHandler($report, $timeDiff, $distanceDiff);
    }

    private function getTransitionHandler(bool $wasStopped, bool $isStopped): callable
    {
        return match (true) {
            $wasStopped && $isStopped => fn($report, $timeDiff, $distanceDiff) => $this->handleStoppedToStopped($report, $timeDiff, $distanceDiff),
            $wasStopped && !$isStopped => fn($report, $timeDiff, $distanceDiff) => $this->handleStoppedToMoving($report, $timeDiff, $distanceDiff),
            !$wasStopped && $isStopped => fn($report, $timeDiff, $distanceDiff) => $this->handleMovingToStopped($report, $timeDiff, $distanceDiff),
            default => fn($report, $timeDiff, $distanceDiff) => $this->handleMovingToMoving($report, $timeDiff, $distanceDiff),
        };
    }

    private function handleStoppedToStopped(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);
        if ($this->latestStoredReport) {
            if (!in_array($this->latestStoredReport, $this->points)) {
                $this->points[] = $this->latestStoredReport;
            }
            $this->latestStoredReport->update([
                'stoppage_time' => $this->latestStoredReport->stoppage_time + $timeDiff,
            ]);
        }
    }

    private function handleStoppedToMoving(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);
        if ($this->latestStoredReport) {
            $this->latestStoredReport->update([
                'stoppage_time' => $this->latestStoredReport->stoppage_time + $timeDiff,
            ]);
        }
        $report['is_starting_point'] = true;
        $this->points[] = $report;
        $this->saveReport($report);
    }

    private function handleMovingToStopped(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, false, true);
        $report['is_ending_point'] = true;
        $this->points[] = $report;
        $this->saveReport($report);
    }

    private function handleMovingToMoving(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    private function shouldCountReport(array $report): bool
    {
        // If there's a current task, only check if the point is within the task area
        if ($this->currentTask && $this->taskArea) {
            return is_point_in_polygon($report['coordinate'], $this->taskArea);
        }

        // If no task is defined, check if the report is within working hours
        return !$this->currentTask && ($this->isWithinWorkingHours)($report);
    }

    private function incrementTimingAndTraveledDistance(array $report, int $timeDiff, float $distanceDiff, bool $stopped = false, bool $incrementStoppage = false): void
    {
        if ($this->shouldCountReport($report)) {
            $this->updateTimingAndDistance($timeDiff, $distanceDiff, $stopped, $incrementStoppage);
        }
    }

    private function updateTimingAndDistance(int $timeDiff, float $distanceDiff, bool $stopped, bool $incrementStoppage): void
    {
        if ($incrementStoppage) {
            $this->stoppageCount += 1;
        }

        $this->{$stopped ? 'totalStoppedTime' : 'totalMovingTime'} += $timeDiff;
        $this->totalTraveledDistance += ($stopped ? 0 : $distanceDiff);
    }

    private function saveReport(array $data): void
    {
        $report = $this->device->reports()->create($data);
        $this->latestStoredReport = $report;
        $this->cacheService->setLatestStoredReport($report);
        // $this->setWorkingTimes($report);
    }
}
