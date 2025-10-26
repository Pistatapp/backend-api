<?php

namespace App\Listeners;

use App\Events\ReportReceived;
use App\Events\TractorZoneStatus;
use App\Models\GpsData;
use App\Models\GpsMetricsCalculation;
use App\Services\GpsDataAnalyzer;
use App\Services\TractorTaskService;
use App\Services\TractorTaskStatusService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReportReceivedListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private TractorTaskService $tractorTaskService,
        private TractorTaskStatusService $tractorTaskStatusService,
        private GpsDataAnalyzer $gpsDataAnalyzer
    ) {}

    /**
     * Handle the event.
     */
    public function handle(ReportReceived $event): void
    {
        try {
            $device = $event->device;
            $points = $event->points;

            if (!$device->tractor) {
                return;
            }

            $tractor = $device->tractor;

            // Process each GPS point
            foreach ($points as $point) {
                $this->processGpsPoint($tractor, $point);
            }

        } catch (\Exception $e) {
            Log::error('Error processing GPS report', [
                'error' => $e->getMessage(),
                'device_id' => $event->device->id ?? null,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Process a single GPS point for task detection and metrics calculation.
     *
     * @param \App\Models\Tractor $tractor
     * @param array $point
     */
    private function processGpsPoint($tractor, array $point): void
    {
        $timestamp = Carbon::parse($point['date_time']);
        $coordinate = $point['coordinate'];

        // Find current active task
        $currentTask = $this->tractorTaskService->getCurrentTask($timestamp, $tractor);

        if (!$currentTask) {
            return;
        }

        // Check if point is in task zone
        $isInZone = $this->tractorTaskService->isPointInTaskZone($coordinate, $currentTask);

        // Update task status based on zone entry/exit
        $this->updateTaskStatus($currentTask, $isInZone);

        // Calculate and update metrics for this task
        $this->updateTaskMetrics($tractor, $currentTask, $timestamp);

        // Fire zone status event
        $this->fireZoneStatusEvent($tractor->gpsDevice, $currentTask, $isInZone);
    }

    /**
     * Update task status based on zone entry/exit.
     *
     * @param \App\Models\TractorTask $task
     * @param bool $isInZone
     */
    private function updateTaskStatus($task, bool $isInZone): void
    {
        if ($isInZone) {
            // Tractor entered zone - mark as in_progress if applicable
            $this->tractorTaskStatusService->markTaskInProgressIfApplicable($task);
        } else {
            // Tractor exited zone - mark as stopped if applicable
            $this->tractorTaskStatusService->markTaskStoppedIfApplicable($task);
        }
    }

    /**
     * Calculate and update GPS metrics for a specific task.
     *
     * @param \App\Models\Tractor $tractor
     * @param \App\Models\TractorTask $task
     * @param Carbon $timestamp
     */
    private function updateTaskMetrics($tractor, $task, Carbon $timestamp): void
    {
        $date = $timestamp->toDateString();

        // Get GPS data for this task's time range and zone
        $gpsData = $this->getGpsDataForTask($tractor, $task, $date);

        if ($gpsData->isEmpty()) {
            return;
        }

        // Calculate metrics using GpsDataAnalyzer
        $analyzer = $this->gpsDataAnalyzer->loadFromRecords($gpsData);

        // Set working time boundaries for the task
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        $analyzer->setWorkingTimeBoundaries($taskStartDateTime, $taskEndDateTime);
        $results = $analyzer->analyze();

        // Update or create GpsMetricsCalculation record
        $this->updateGpsMetricsRecord($tractor, $task, $date, $results);
    }

    /**
     * Get GPS data filtered for a specific task (time range and zone).
     *
     * @param \App\Models\Tractor $tractor
     * @param \App\Models\TractorTask $task
     * @param string $date
     * @return \Illuminate\Support\Collection
     */
    private function getGpsDataForTask($tractor, $task, string $date): \Illuminate\Support\Collection
    {
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Get GPS data for the tractor on the task date
        $gpsData = GpsData::where('tractor_id', $tractor->id)
            ->whereDate('date_time', $date)
            ->whereBetween('date_time', [$taskStartDateTime, $taskEndDateTime])
            ->orderBy('date_time')
            ->get();

        // Filter points that are in the task zone
        $taskZone = $this->tractorTaskService->getTaskZone($task);

        if (!$taskZone) {
            return collect();
        }

        return $gpsData->filter(function ($point) use ($taskZone) {
            return is_point_in_polygon($point->coordinate, $taskZone);
        });
    }

    /**
     * Update or create GpsMetricsCalculation record for a task.
     *
     * @param \App\Models\Tractor $tractor
     * @param \App\Models\TractorTask $task
     * @param string $date
     * @param array $results
     */
    private function updateGpsMetricsRecord($tractor, $task, string $date, array $results): void
    {
        DB::transaction(function () use ($tractor, $task, $date, $results) {
            $record = GpsMetricsCalculation::updateOrCreate(
                [
                    'tractor_id' => $tractor->id,
                    'tractor_task_id' => $task->id,
                    'date' => $date,
                ],
                [
                    'traveled_distance' => $results['movement_distance_km'],
                    'work_duration' => $results['movement_duration_seconds'],
                    'stoppage_count' => $results['stoppage_count'],
                    'stoppage_duration' => $results['stoppage_duration_seconds'],
                    'stoppage_duration_while_on' => $results['stoppage_duration_while_on_seconds'],
                    'stoppage_duration_while_off' => $results['stoppage_duration_while_off_seconds'],
                    'average_speed' => $results['average_speed'],
                    'max_speed' => $results['max_speed'] ?? 0,
                    'efficiency' => $this->calculateTaskEfficiency($tractor, $results['movement_duration_seconds']),
                ]
            );
        });
    }

    /**
     * Calculate task efficiency based on work duration.
     *
     * @param \App\Models\Tractor $tractor
     * @param int $workDurationSeconds
     * @return float
     */
    private function calculateTaskEfficiency($tractor, int $workDurationSeconds): float
    {
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;

        if ($expectedDailyWorkSeconds <= 0) {
            return 0;
        }

        return ($workDurationSeconds / $expectedDailyWorkSeconds) * 100;
    }

    /**
     * Fire TractorZoneStatus event.
     *
     * @param \App\Models\GpsDevice $device
     * @param \App\Models\TractorTask $task
     * @param bool $isInZone
     */
    private function fireZoneStatusEvent($device, $task, bool $isInZone): void
    {
        // Get current work duration in zone
        $metrics = GpsMetricsCalculation::where('tractor_task_id', $task->id)
            ->where('date', $task->date)
            ->first();

        $workDurationInZone = $metrics ? $metrics->work_duration : 0;

        event(new TractorZoneStatus(
            [
                'is_in_task_zone' => $isInZone,
                'task_id' => $task->id,
                'task_name' => $task->operation?->name ?? 'Unknown Task',
                'work_duration_in_zone' => $workDurationInZone,
            ],
            $device
        ));
    }
}
