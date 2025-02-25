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
    private $tasks,
        $currentTask,
        $dailyReport,
        $latestStoredReport,
        $totalTraveledDistance,
        $totalMovingTime,
        $totalStoppedTime,
        $stoppageCount,
        $tractor,
        $points = [],
        $maxSpeed,
        $taskAreas = [];

    public function __construct(
        private GpsDevice $device,
        private array $reports,
    ) {
        $this->tractor = $device->tractor;
        $this->tasks = $this->getTasks();
        $this->dailyReport = $this->getDailyReport();
        $this->latestStoredReport = $this->getLatestStoredReport();
        $this->maxSpeed = $this->dailyReport->max_speed;
        $this->currentTask = $this->tasks->where('tractor_id', $this->tractor->id)->first();
        $this->taskAreas = isset($this->currentTask) ? $this->getTaskAreas() : [];
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
    private function calculateTimingAndTraveledDistance()
    {
        $previousReport = Cache::get('previous_report_' . $this->device->id);

        foreach ($this->reports as $report) {
            if (is_null($previousReport)) {
                $this->save($report);
                $this->points[] = $report;
                $this->maxSpeed = $report['speed'];
                if ($report['is_stopped'] && $this->isIntractorTime($report)) {
                    $this->stoppageCount += 1;
                }
            } else {

                $this->maxSpeed = max($this->maxSpeed, $report['speed']);

                $distanceDiff = $this->calculateDistance($previousReport, $report);

                $timeDiff = $previousReport['date_time']->diffInSeconds($report['date_time']);

                if ($previousReport['is_stopped'] && $report['is_stopped']) {
                    $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);

                    if (!in_array($this->latestStoredReport, $this->points)) {
                        $this->points[] = $this->latestStoredReport;
                    }

                    $this->latestStoredReport->update([
                        'stoppage_time' => $this->latestStoredReport->stoppage_time + $timeDiff,
                    ]);
                } elseif ($previousReport['is_stopped'] && !$report['is_stopped']) {
                    $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, true);
                    $this->latestStoredReport->update([
                        'stoppage_time' => $this->latestStoredReport->stoppage_time + $timeDiff,
                    ]);
                    $this->points[] = $report;
                    $this->save($report);
                } elseif (!$previousReport['is_stopped'] && $report['is_stopped']) {
                    $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff, false, true);
                    $this->points[] = $report;
                    $this->save($report);
                } elseif (!$previousReport['is_stopped'] && !$report['is_stopped']) {
                    $this->incrementTimingAndTraveledDistance($report, $timeDiff, $distanceDiff);
                    $this->points[] = $report;
                    $this->save($report);
                }
            }

            $previousReport = $report;
            Cache::put('previous_report_' . $this->device->id, $previousReport, now()->endOfDay());
        }
    }

    /**
     * Increment the timing and traveled distance.
     * @param  array  $parameters
     * @return void
     */
    private function incrementTimingAndTraveledDistance(...$parameters)
    {
        $report = $parameters[0];
        $timeDiff = $parameters[1];
        $distanceDiff = $parameters[2];
        $stopped = $parameters[3] ?? false;
        $incrementStoppage = $parameters[4] ?? false;

        if (isset($this->currentTask) && $this->isIntractorTime($report)) {
            foreach ($this->taskAreas as $area) {
                if ($this->isReportInTaskArea($report, $area)) {
                    if ($incrementStoppage) {
                        $this->stoppageCount += 1;
                    }

                    $this->{$stopped ? 'totalStoppedTime' : 'totalMovingTime'} += $timeDiff;
                    $this->totalTraveledDistance += ($stopped ? 0 : $distanceDiff);
                }
            }
        } elseif ($this->isIntractorTime($report)) {
            if ($incrementStoppage) {
                $this->stoppageCount += 1;
            }

            $this->{$stopped ? 'totalStoppedTime' : 'totalMovingTime'} += $timeDiff;
            $this->totalTraveledDistance += ($stopped ? 0 : $distanceDiff);
        }
    }

    /**
     * Check if the point is in taskArea.
     *
     * @param  array  $report
     * @return bool
     */
    protected function isReportInTaskArea(array $report, array $area)
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
    private function isIntractorTime(array $report)
    {
        return $report['date_time']->gte($this->tractor->start_work_time)
            && $report['date_time']->lte($this->tractor->end_work_time);
    }

    /**
     * Get the task areas.
     *
     * @return array
     */
    private function getTaskAreas()
    {
        return Cache::remember('task_fields_' . $this->currentTask->id, 60 * 60, function () {
            return Field::whereIn('id', $this->currentTask->field_ids)
                ->get()
                ->map(function ($field) {
                    return collect($field->coordinates)
                        ->map(function ($coordinate) {
                            [$lat, $lng] = explode(',', $coordinate);
                            return ['lat' => $lat, 'lng' => $lng];
                        })
                        ->toArray();
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
    protected function calculateDistance($point1, $point2)
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
    private function save(array $data)
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
        return Cache::remember('tasks', now()->endOfDay(), function () {
            return TractorTask::forDate(today())->get();
        });
    }

    /**
     * Get daily reports for the tractor
     *
     * @return GPSDailyReport
     */
    private function getDailyReport()
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
    private function getLatestStoredReport()
    {
        $latestStoredReportId = Cache::get('latest_stored_report_id' . $this->device->id);
        return GpsReport::find($latestStoredReportId);
    }

    /**
     * Update the daily report.
     *
     * @return array $data
     */
    private function updateDailyReport()
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
    public function calculateEfficiency()
    {
        return $this->totalMovingTime / ($this->tractor->expected_daily_work_time * 3600) * 100;
    }

    /**
     * Calculate the average speed of the tractor.
     *
     * @return float
     */
    public function calculateAverageSpeed()
    {
        return $this->dailyReport->work_duration > 0 ? $this->dailyReport->traveled_distance / ($this->dailyReport->work_duration / 3600) : 0;
    }

    /**
     * Set the start working time.
     *
     * @param  \App\Models\Report  $report
     * @return void
     */
    private function setStartWorkingTime($report): void
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
    private function setEndWorkingTime($report): void
    {
        if (!Cache::has('ending_time_' . $this->tractor->id)) {
            if ($report->is_stopped && $report->date_time->gte($this->tractor->end_work_time)) {
                $report->update(['is_ending_point' => true]);
                Cache::put('end_working_time_' . $this->tractor->id, $report->date_work_time, now()->endOfDay());
            }
        }
    }
}
