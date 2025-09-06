<?php

namespace App\Services;

use App\Models\Tractor;
use Carbon\Carbon;
use App\Http\Resources\PointsResource;
use App\Http\Resources\TractorTaskResource;
use App\Http\Resources\DriverResource;
/**
 * This constructor initializes the Kalman filter.
 *
 * @param KalmanFilter $kalmanFilter
 */
class TractorReportService
{
    public function __construct(
        private KalmanFilter $kalmanFilter
    ) {}

    /**
     * Provides daily reports for a given tractor and date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    public function getDailyReport(Tractor $tractor, Carbon $date): array
    {
        $dailyReport = $tractor->gpsDailyReports()->where('date', $date)->first();
        $reports = $this->getFilteredReports($tractor, $date);
        $startWorkingTime = $this->getStartWorkingTime($reports);
        $currentTask = $this->getCurrentTask($tractor);

        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'speed' => $reports->last()->speed ?? 0,
            'status' => $reports->last()->status ?? 0,
            'start_working_time' => $startWorkingTime ? $startWorkingTime->date_time->format('H:i:s') : '00:00:00',
            'traveled_distance' => number_format($dailyReport->traveled_distance ?? 0, 2),
            'work_duration' => gmdate('H:i:s', $dailyReport->work_duration ?? 0),
            'stoppage_count' => $dailyReport->stoppage_count ?? 0,
            'stoppage_duration' => gmdate('H:i:s', $dailyReport->stoppage_duration ?? 0),
            'efficiency' => number_format($dailyReport->efficiency ?? 0, 2),
            'points' => PointsResource::collection($reports),
            'current_task' => $currentTask ? new TractorTaskResource($currentTask) : null,
        ];
    }

    /**
     * Retrieves the tractor path for a specific date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getTractorPath(Tractor $tractor, Carbon $date): \Illuminate\Support\Collection
    {
        return $this->getFilteredReports($tractor, $date);
    }

    /**
     * Returns tractor details and summary data.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return array
     */
    public function getTractorDetails(Tractor $tractor, Carbon $date): array
    {
        $dailyReport = $tractor->gpsDailyReports()->where('date', $date)->first();
        $reports = $tractor->gpsReports()->whereDate('date_time', $date)->orderBy('date_time')->get();
        $startWorkingTime = $this->getStartWorkingTime($reports);
        $currentTask = $this->getCurrentTask($tractor);
        $tractor->load('driver');

        // Get last 7 days efficiency data
        $lastSevenDaysEfficiency = $tractor->gpsDailyReports()
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->limit(7)
            ->get()
            ->map(function ($report) {
                return [
                    'date' => jdate($report->date)->format('Y/m/d'),
                    'efficiency' => number_format($report->efficiency, 2)
                ];
            });

        return [
            'id' => $tractor->id,
            'name' => $tractor->name,
            'speed' => intval($reports->avg('speed') ?? 0),
            'status' => $reports->last()->status ?? 0,
            'start_working_time' => $startWorkingTime ? $startWorkingTime->date_time->format('H:i:s') : '00:00:00',
            'traveled_distance' => number_format($dailyReport->traveled_distance ?? 0, 2),
            'work_duration' => gmdate('H:i:s', $dailyReport->work_duration ?? 0),
            'stoppage_count' => $dailyReport->stoppage_count ?? 0,
            'stoppage_duration' => gmdate('H:i:s', $dailyReport->stoppage_duration ?? 0),
            'efficiency' => number_format($dailyReport->efficiency ?? 0, 2),
            'current_task' => $currentTask ? new TractorTaskResource($currentTask) : null,
            'last_seven_days_efficiency' => $lastSevenDaysEfficiency,
            'driver' => $tractor->driver ? new DriverResource($tractor->driver) : null,
        ];
    }

    /**
     * Filters the tractor’s GPS reports using the Kalman filter.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    private function getFilteredReports(Tractor $tractor, Carbon $date): \Illuminate\Support\Collection
    {
        return $tractor->gpsReports()
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->get()
            ->map(function ($report) {
                $filtered = $this->kalmanFilter->filter($report->coordinate[0], $report->coordinate[1]);
                $report->coordinate = [$filtered['latitude'], $filtered['longitude']];
                return $report;
            });
    }

    /**
     * Finds the start working time from reports.
     *
     * @param \Illuminate\Support\Collection $reports
     * @return mixed
     */
    private function getStartWorkingTime($reports)
    {
        return count($reports) > 0 ? $reports->where('is_starting_point', 1)->first() : null;
    }

    /**
     * Gets the tractor’s current task.
     *
     * @param Tractor $tractor
     * @return mixed
     */
    private function getCurrentTask(Tractor $tractor)
    {
        return $tractor->tasks()->with('operation', 'taskable', 'creator')->started()->first();
    }
}
