<?php

namespace App\Services;

use App\Models\Field;
use App\Models\GpsDailyReport;
use App\Models\GpsDevice;
use App\Models\GpsReport;
use App\Models\TractorTask;
use Illuminate\Support\Facades\Cache;

class LiveReportService
{
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
        $this->tasks = $this->getTasks();
        $this->dailyReport = $this->getDailyReport();
        $this->latestStoredReport = $this->getLatestStoredReport();
        $this->maxSpeed = $this->dailyReport->max_speed;
        $this->currentTask = $this->tasks->where('tractor_id', $this->tractor->id)->first();
        $this->taskArea = isset($this->currentTask) ? $this->getTaskArea() : [];
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
        if ($report['is_stopped'] && $this->isInTractorTime($report)) {
            $this->stoppageCount += 1;
        }
    }

    private function processSubsequentReports(array $previousReport, array $report): void
    {
        $this->maxSpeed = max($this->maxSpeed, $report['speed']);
        $distanceDiff = $this->calculateDistance($previousReport, $report);
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
        if (isset($this->currentTask) && $this->isInTractorTime($report)) {
            foreach ($this->taskAreas as $area) {
                if ($this->isReportInTaskArea($report, $area)) {
                    $this->updateTimingAndDistance($timeDiff, $distanceDiff, $stopped, $incrementStoppage);
                }
            }
        } elseif ($this->isInTractorTime($report)) {
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
     * Check if the point is in taskArea.
     *
     * @param  array  $report
     * @return bool
     */
    protected function isReportInTaskArea(array $report, array $area): bool
    {
        $point = [
            'lat' => $report['latitude'],
            'lng' => $report['longitude'],
        ];

        $vertices_x = [];
        $vertices_y = [];

        foreach ($area as $vertex) {
            $vertices_x[] = $vertex['lng']; // longitude corresponds to x
            $vertices_y[] = $vertex['lat']; // latitude corresponds to y
        }

        $points_taskArea = count($vertices_x) - 1;
        $i = $j = $c = 0;

        for ($i = 0, $j = $points_taskArea; $i < $points_taskArea; $j = $i++) {
            if ((($vertices_y[$i] > $point['lng']) != ($vertices_y[$j] > $point['lng'])) &&
                ($point['lat'] < ($vertices_x[$j] - $vertices_x[$i]) *
                    ($point['lng'] - $vertices_y[$i]) /
                    ($vertices_y[$j] - $vertices_y[$i]) + $vertices_x[$i])
            ) {
                $c = !$c;
            }
        }

        return $c;
    }

    /**
     * Check if the report is in tractor time.
     *
     * @param  array  $report
     * @return bool
     */
    private function isInTractorTime(array $report): bool
    {
        return $report['date_time']->gte($this->tractor->start_work_time)
            && $report['date_time']->lte($this->tractor->end_work_time);
    }

    /**
     * Get the task area.
     *
     * @return array
     */
    private function getTaskArea(): array
    {
        return Cache::remember('task_field_' . $this->currentTask->id, 60 * 60, function () {
            $field = $this->currentTask->field;

            return collect($field->coordinates)
                ->map(function ($coordinate) {
                    [$lat, $lng] = explode(',', $coordinate);
                    return ['lat' => $lat, 'lng' => $lng];
                })
                ->toArray();
        });
    }

    /**
     * Calculate distance between two points.
     *
     * @param  object  $point1
     * @param  object  $point2
     * @return float
     */
    protected function calculateDistance(array $point1, array $point2): float
    {
        $earthRadiusKm = 6371;

        $lat1 = deg2rad($point1['latitude']);
        $lng1 = deg2rad($point1['longitude']);
        $lat2 = deg2rad($point2['latitude']);
        $lng2 = deg2rad($point2['longitude']);

        $deltaLat = $lat2 - $lat1;
        $deltaLng = $lng2 - $lng1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
            cos($lat1) * cos($lat2) *
            sin($deltaLng / 2) * sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
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
        $this->setStartWorkingTime($report);
        $this->setEndWorkingTime($report);
    }

    /**
     * Get tasks for the day.
     *
     * @return \Illuminate\Support\Collection
     */
    private function getTasks()
    {
        return Cache::remember('tasks', now()->addHour(), function () {
            return TractorTask::with('field')->forPresentTime()->get();
        });
    }

    /**
     * Get daily reports for the tractor
     *
     * @return GPSDailyReport
     */
    private function getDailyReport(): GpsDailyReport
    {
        return GpsDailyReport::firstOrCreate([
            'tractor_id' => $this->tractor->id,
            'date' => today()
        ]);
    }

    /**
     * Get the latest stored report.
     *
     * @return GPSReport
     */
    private function getLatestStoredReport(): ?GpsReport
    {
        $latestStoredReportId = Cache::get('latest_stored_report_id' . $this->device->id);
        return GpsReport::find($latestStoredReportId);
    }

    /**
     * Update the daily report.
     *
     * @return array $data
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
        return $this->dailyReport->work_duration > 0 ? $this->dailyReport->traveled_distance / ($this->dailyReport->work_duration / 3600) : 0;
    }

    /**
     * Set the start working time.
     *
     * @param  \App\Models\Report  $report
     * @return void
     */
    private function setStartWorkingTime(GpsReport $report): void
    {
        if (!Cache::has('start_working_time_' . $this->tractor->id)) {
            if (!$report->is_stopped && $report->date_time->gte($this->tractor->start_work_time)) {
                $report->update(['is_starting_point' => true]);
                Cache::put('start_working_time_' . $this->tractor->id, $report->date_time, now()->endOfDay());
            }
        }
    }

    /**
     * Set the end working time.
     *
     * @param  \App\Models\Report  $report
     * @return void
     */
    private function setEndWorkingTime(GpsReport $report): void
    {
        if (!Cache::has('ending_time_' . $this->tractor->id)) {
            if ($report->is_stopped && $report->date_time->gte($this->tractor->end_work_time)) {
                $report->update(['is_ending_point' => true]);
                Cache::put('end_working_time_' . $this->tractor->id, $report->date_work_time, now()->endOfDay());
            }
        }
    }
}
