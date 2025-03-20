<?php

namespace App\Services;

use App\Models\GpsDailyReport;
use App\Models\GpsDevice;
use App\Models\GpsReport;
use App\Models\TractorTask;
use Illuminate\Support\Facades\Cache;
use App\Traits\DistanceCalculator;
use App\Traits\TractorWorkingTime;

class LiveReportService
{
    use DistanceCalculator, TractorWorkingTime;

    private $tasks;
    private $currentTask;
    private $dailyReport;
    private $latestStoredReport;
    private $totalTraveledDistance = 0;
    private $totalMovingTime = 0;
    private $totalStoppedTime = 0;
    private $stoppageCount = 0;
    private $tractor;
    private $points = [];
    private $maxSpeed;
    private $taskArea;

    public function __construct(
        private GpsDevice $device,
        private array $reports,
    ) {
        $this->initialize();
    }

    private function initialize(): void
    {
        $this->tractor = $this->device->tractor;
        $this->tasks = $this->fetchDailyTasks();
        $this->dailyReport = $this->fetchOrCreateDailyReport();
        $this->latestStoredReport = $this->fetchLatestStoredReport();
        $this->maxSpeed = $this->dailyReport->max_speed;
        $this->currentTask = $this->tasks->where('tractor_id', $this->tractor->id)->first();
        $this->taskArea = isset($this->currentTask) ? $this->currentTask->fetchTaskArea() : [];
    }

    /**
     * Generate the live report.
     *
     * @return array
     */
    public function generate(): array
    {
        $this->calculateTimingAndTraveledDistance();

        $data = $this->updateDailyReport();

        return [
            'id' => $this->dailyReport->id,
            'tractor_id' => $this->tractor->id,
            'traveled_distance' => $data['traveled_distance'],
            'work_duration' => $data['work_duration'],
            'stoppage_duration' => $data['stoppage_duration'],
            'efficiency' => $data['efficiency'],
            'stoppage_count' => $data['stoppage_count'],
            'speed' => $this->latestStoredReport['speed'],
            'points' => $this->points,
        ];
    }

    /**
     * Calculate the timing and traveled distance.
     *
     * @return void
     */
    private function calculateTimingAndTraveledDistance(): void
    {
        $previousReport = Cache::get('previous_report_' . $this->device->id);

        foreach ($this->reports as $report) {
            if (is_null($previousReport)) {
                $this->processFirstReport($report);
            } else {
                $this->processSubsequentReports($previousReport, $report);
            }

            $previousReport = $report;
            Cache::put('previous_report_' . $this->device->id, $previousReport, now()->endOfDay());
        }
    }

    private function processFirstReport(array $report): void
    {
        $this->saveReport($report);
        $this->points[] = $report;
        $this->maxSpeed = $report['speed'];
        if ($report['is_stopped'] && $this->isWithinWorkingHours($report)) {
            $this->stoppageCount += 1;
        }
    }

    private function processSubsequentReports(array $previousReport, array $report): void
    {
        $this->maxSpeed = max($this->maxSpeed, $report['speed']);
        $distanceDiff = calculate_distance($previousReport, $report);
        $timeDiff = $previousReport['date_time']->diffInSeconds($report['date_time']);

        if ($previousReport['is_stopped'] && $report['is_stopped']) {
            $this->handleStoppedToStopped($report, $timeDiff, $distanceDiff);
        } elseif ($previousReport['is_stopped'] && !$report['is_stopped']) {
            $this->handleStoppedToMoving($report, $timeDiff, $distanceDiff);
        } elseif (!$previousReport['is_stopped'] && $report['is_stopped']) {
            $this->handleMovingToStopped($report, $timeDiff, $distanceDiff);
        } else {
            $this->handleMovingToMoving($report, $timeDiff, $distanceDiff);
        }
    }

    private function handleStoppedToStopped(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);
        if (!in_array($this->latestStoredReport, $this->points)) {
            $this->points[] = $this->latestStoredReport;
        }
        $this->latestStoredReport->update([
            'stoppage_time' => $this->latestStoredReport->stoppage_time + $timeDiff,
        ]);
    }

    private function handleStoppedToMoving(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);
        $this->latestStoredReport->update([
            'stoppage_time' => $this->latestStoredReport->stoppage_time + $timeDiff,
        ]);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    private function handleMovingToStopped(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, false, true);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    private function handleMovingToMoving(array $report, int $timeDiff, float $distanceDiff): void
    {
        $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff);
        $this->points[] = $report;
        $this->saveReport($report);
    }

    /**
     * Increment the timing and traveled distance.
     * @param  array  $parameters
     * @return void
     */
    private function incrementTimingAndTraveledDistance(array $report, int $timeDiff, float $distanceDiff, bool $stopped = false, bool $incrementStoppage = false): void
    {
        if (isset($this->currentTask) && $this->isWithinWorkingHours($report)) {
            if (is_point_in_polygon($report, $this->taskArea)) {
                $this->updateTimingAndDistance($timeDiff, $distanceDiff, $stopped, $incrementStoppage);
            }
        } elseif ($this->isWithinWorkingHours($report)) {
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

    /**
     * Save the report.
     *
     * @param  array  $data
     * @return void
     */
    private function saveReport(array $data): void
    {
        $report =  $this->device->reports()->create($data);
        $this->latestStoredReport = $report;
        Cache::put('latest_stored_report_id' . $this->device->id, $report->id, now()->endOfDay());
        $this->setWorkingTimes($report);
    }

    /**
     * Fetch daily tasks for the tractor.
     *
     * @return \Illuminate\Support\Collection
     */
    private function fetchDailyTasks()
    {
        return Cache::remember('tasks', now()->addHour(), function () {
            return TractorTask::with('field:id,coordinates')->forPresentTime()->get();
        });
    }

    /**
     * Fetch or create the daily report for the tractor.
     *
     * @return GpsDailyReport
     */
    private function fetchOrCreateDailyReport(): GpsDailyReport
    {
        return GpsDailyReport::firstOrCreate([
            'tractor_id' => $this->tractor->id,
            'date' => today()
        ]);
    }

    /**
     * Fetch the latest stored report for the tractor.
     *
     * @return GpsReport|null
     */
    private function fetchLatestStoredReport(): ?GpsReport
    {
        $latestStoredReportId = Cache::get('latest_stored_report_id' . $this->device->id);
        return GpsReport::find($latestStoredReportId);
    }

    /**
     * Update the daily report with calculated data.
     *
     * @return array
     */
    private function updateDailyReport(): array
    {
        $efficiency = $this->calculateEfficiency();

        $data = [
            'traveled_distance' => $this->dailyReport->traveled_distance + $this->totalTraveledDistance,
            'work_duration' => $this->dailyReport->work_duration + $this->totalMovingTime,
            'stoppage_duration' => $this->dailyReport->stoppage_duration + $this->totalStoppedTime,
            'efficiency' => $this->dailyReport->efficiency + $efficiency,
            'stoppage_count' => $this->dailyReport->stoppage_count + $this->stoppageCount,
            'max_speed' => $this->maxSpeed,
            'average_speed' => $this->calculateAverageSpeed(),
        ];

        $this->dailyReport->update($data);

        return $data;
    }

    /**
     * Calculate the efficiency of the tractor.
     *
     * @return float
     */
    public function calculateEfficiency(): float
    {
        return $this->totalMovingTime / ($this->tractor->expected_daily_work_time * 3600) * 100;
    }

    /**
     * Calculate the average speed of the tractor.
     *
     * @return float
     */
    public function calculateAverageSpeed(): float
    {
        return $this->dailyReport->work_duration > 0
            ? $this->dailyReport->traveled_distance / ($this->dailyReport->work_duration / 3600)
            : 0;
    }
}
