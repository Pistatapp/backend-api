<?php

namespace App\Jobs;

use App\Models\Tractor;
use App\Models\TractorEfficiencyChart;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CalculateTractorEfficiencyChartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Disable query log to reduce memory usage in long-running jobs
        DB::disableQueryLog();

        // Calculate efficiency for the previous day only
        $previousDay = Carbon::yesterday();

        // Process all tractors in chunks with minimal selected columns
        Tractor::query()
            ->select(['id', 'expected_daily_work_time', 'start_work_time', 'end_work_time'])
            ->chunkById(100, function ($tractors) use ($previousDay) {
            foreach ($tractors as $tractor) {
                $this->calculateEfficiencyForDate($tractor, $previousDay);

                    // Aggressively free memory between tractors
                    unset($tractor);
                    gc_collect_cycles();
            }
        });
    }

    /**
     * Calculate efficiency for a specific tractor on a specific date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return void
     */
    private function calculateEfficiencyForDate(
        Tractor $tractor,
        Carbon $date
    ): void {
        try {
            $totalEfficiency = $this->calculateTotalEfficiency($tractor, $date);
            $taskBasedEfficiency = $this->calculateTaskBasedEfficiency($tractor, $date);

            // Store or update the efficiency chart data
            TractorEfficiencyChart::updateOrCreate(
                [
                    'tractor_id' => $tractor->id,
                    'date' => $date->toDateString(),
                ],
                [
                    'total_efficiency' => $totalEfficiency,
                    'task_based_efficiency' => $taskBasedEfficiency,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Error calculating efficiency for tractor', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate total efficiency for a tractor on a date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return float
     */
    private function calculateTotalEfficiency(
        Tractor $tractor,
        Carbon $date
    ): float {
        // Determine optional working window
        $startDateTime = null;
        $endDateTime = null;
        if ($tractor->start_work_time && $tractor->end_work_time) {
            $startDateTime = $date->copy()->setTimeFromTimeString($tractor->start_work_time);
            $endDateTime = $date->copy()->setTimeFromTimeString($tractor->end_work_time);
            if ($endDateTime->lt($startDateTime)) {
                $endDateTime->addDay();
            }
        } else {
            // Whole day window
            $startDateTime = $date->copy()->startOfDay();
            $endDateTime = $date->copy()->endOfDay();
        }

        $workDurationSeconds = $this->streamMovementDuration($tractor, $startDateTime, $endDateTime, null);
        if ($workDurationSeconds <= 0) {
            return 0.00;
        }

        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
        $totalEfficiency = $expectedDailyWorkSeconds > 0 ? ($workDurationSeconds / $expectedDailyWorkSeconds) * 100 : 0;

        return round($totalEfficiency, 2);
    }

    /**
     * Calculate task-based efficiency for a tractor on a date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return float
     */
    private function calculateTaskBasedEfficiency(
        Tractor $tractor,
        Carbon $date
    ): float {
        $tractorTaskService = app(\App\Services\TractorTaskService::class);
        $tasks = $tractorTaskService->getAllTasksForDate($tractor, $date);

        if ($tasks->isEmpty()) {
            return 0.00;
        }

        $taskEfficiencies = [];

        foreach ($tasks as $task) {
            // Task window
            $taskDateTime = Carbon::parse($task->date);
            $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
            $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);
            if ($taskEndDateTime->lt($taskStartDateTime)) {
                $taskEndDateTime->addDay();
            }

            // Zone filter (if any)
            $taskZone = $tractorTaskService->getTaskZone($task);
            $pointFilter = null;
            if ($taskZone) {
                $pointFilter = function (float $lat, float $lon) use ($taskZone): bool {
                    return is_point_in_polygon([$lat, $lon], $taskZone);
                };
            }

            $movementDuration = $this->streamMovementDuration($tractor, $taskStartDateTime, $taskEndDateTime, $pointFilter);
            if ($movementDuration > 0) {
                $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
                $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;
                $efficiency = $expectedDailyWorkSeconds > 0
                    ? ($movementDuration / $expectedDailyWorkSeconds) * 100
                    : 0;
                $taskEfficiencies[] = $efficiency;
            }

            // Free memory aggressively in loop
            unset($taskZone, $pointFilter);
        }

        if (empty($taskEfficiencies)) {
            return 0.00;
        }

        $averageEfficiency = array_sum($taskEfficiencies) / count($taskEfficiencies);
        return round($averageEfficiency, 2);
    }

    /**
     * Get GPS data for a specific task (filtered by time range and zone).
     *
     * @param Tractor $tractor
     * @param \App\Models\TractorTask $task
     * @param Carbon $date
     * @return \Illuminate\Support\Collection
     */
    private function getGpsDataForTask(Tractor $tractor, \App\Models\TractorTask $task, Carbon $date): \Illuminate\Support\Collection
    {
        $taskDateTime = Carbon::parse($task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Get GPS data for the tractor on the task date
        $gpsData = $tractor->gpsData()
            ->whereDate('date_time', $date)
            ->whereBetween('date_time', [$taskStartDateTime, $taskEndDateTime])
            ->orderBy('date_time')
            ->get();

        // Filter points that are in the task zone
        $tractorTaskService = app(\App\Services\TractorTaskService::class);
        $taskZone = $tractorTaskService->getTaskZone($task);

        if (!$taskZone) {
            return collect();
        }

        return $gpsData->filter(function ($point) use ($taskZone) {
            return is_point_in_polygon($point->coordinate, $taskZone);
        });
    }

    /**
     * Stream GPS rows to compute movement duration without loading all records into memory.
     * Movement is defined as status == 1 and speed > 0.
     * Optionally filters points by a predicate on latitude/longitude.
     *
     * @param Tractor $tractor
     * @param Carbon $start
     * @param Carbon $end
     * @param callable|null $pointFilter function(float $lat, float $lon): bool
     * @return int movement duration in seconds
     */
    private function streamMovementDuration(Tractor $tractor, Carbon $start, Carbon $end, ?callable $pointFilter): int
    {
        $movementDuration = 0;
        $previous = null; // ['timestamp' => Carbon, 'moving' => bool]

        // Build minimal base query
        $query = $tractor->gpsData()
            ->select(['gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time'])
            ->toBase()
            ->whereBetween('gps_data.date_time', [$start, $end])
            ->orderBy('gps_data.date_time');

        $query->chunk(1000, function ($rows) use (&$movementDuration, &$previous, $start, $end, $pointFilter) {
            foreach ($rows as $row) {
                // Optional zone filter
                if ($pointFilter !== null) {
                    $coord = $row->coordinate;
                    if (is_string($coord)) {
                        $parts = array_map('floatval', explode(',', $coord));
                        $lat = $parts[0] ?? 0.0;
                        $lon = $parts[1] ?? 0.0;
                    } else {
                        $lat = isset($coord[0]) ? (float)$coord[0] : 0.0;
                        $lon = isset($coord[1]) ? (float)$coord[1] : 0.0;
                    }
                    if (!$pointFilter($lat, $lon)) {
                        // For points outside the zone, reset continuity so time outside isn't counted
                        $currentTimestamp = $row->date_time instanceof Carbon ? $row->date_time : Carbon::parse($row->date_time);
                        $previous = [
                            'timestamp' => $currentTimestamp,
                            'moving' => false,
                        ];
                        continue;
                    }
                }

                $currentTimestamp = $row->date_time instanceof Carbon ? $row->date_time : Carbon::parse($row->date_time);
                $isMoving = ((int)$row->status) === 1 && ((float)$row->speed) > 0;

                if ($previous !== null) {
                    // Clamp interval to [start, end]
                    $intervalStart = $previous['timestamp']->greaterThan($start) ? $previous['timestamp'] : $start;
                    $intervalEnd = $currentTimestamp->lessThan($end) ? $currentTimestamp : $end;

                    if ($intervalEnd->gt($intervalStart)) {
                        $diff = $intervalEnd->diffInSeconds($intervalStart);
                        // Attribute the interval to the previous point's state
                        // Also treat short stoppages (<60s) as movement (to match analyzeLight)
                        if ($previous['moving']) {
                            $movementDuration += $diff;
                        } elseif ($diff < 60) {
                            $movementDuration += $diff;
                        }
                    }
                }

                $previous = [
                    'timestamp' => $currentTimestamp,
                    'moving' => $isMoving,
                ];
            }
            // Encourage releasing chunk memory
            unset($rows);
        });

        return $movementDuration;
    }
}
