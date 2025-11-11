<?php

namespace App\Jobs;

use App\Models\Tractor;
use App\Models\TractorEfficiencyChart;
use App\Services\GpsDataAnalyzer;
use Carbon\Carbon;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CalculateSingleTractorEfficiencyJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * The maximum number of exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * The tractor ID to process.
     *
     * @var int
     */
    protected $tractorId;

    /**
     * The date to calculate efficiency for.
     *
     * @var string
     */
    protected $dateString;

    /**
     * Create a new job instance.
     */
    public function __construct(int $tractorId, Carbon $date)
    {
        $this->tractorId = $tractorId;
        $this->dateString = $date->toDateString();

        // Set queue priority - you can customize this based on tractor importance
        $this->onQueue('efficiency');
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array
     */
    public function middleware(): array
    {
        // Prevent overlapping jobs for the same tractor
        return [
            (new WithoutOverlapping($this->tractorId))
                ->releaseAfter(60) // Release lock after 60 seconds if job fails
                ->expireAfter(300) // Lock expires after 5 minutes
        ];
    }

    /**
     * Calculate the number of seconds before the job should be retried.
     *
     * @return array
     */
    public function backoff(): array
    {
        // Exponential backoff: 30s, 60s, 120s
        return [30, 60, 120];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Disable query log to reduce memory usage
        DB::disableQueryLog();

        // Load tractor with minimal columns
        $tractor = Tractor::select(['id', 'expected_daily_work_time', 'start_work_time', 'end_work_time'])
            ->find($this->tractorId);

        if (!$tractor) {
            Log::warning('Tractor not found for efficiency calculation', [
                'tractor_id' => $this->tractorId,
            ]);
            return;
        }

        $date = Carbon::parse($this->dateString);

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

            Log::info('Calculated efficiency for tractor', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'total_efficiency' => $totalEfficiency,
                'task_based_efficiency' => $taskBasedEfficiency,
            ]);
        } catch (\Exception $e) {
            Log::error('Error calculating efficiency for tractor', [
                'tractor_id' => $tractor->id,
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        } finally {
            // Aggressively free memory
            unset($tractor);
            gc_collect_cycles();
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

        $workDurationSeconds = $this->calculateMovementDuration($tractor, $startDateTime, $endDateTime, null);
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

            $movementDuration = $this->calculateMovementDuration($tractor, $taskStartDateTime, $taskEndDateTime, $pointFilter);
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
    private function calculateMovementDuration(
        Tractor $tractor,
        Carbon $start,
        Carbon $end,
        ?callable $pointFilter
    ): int
    {
        // Build minimal base query to pull GPS points within the requested window
        $query = $tractor->gpsData()
            ->select(['gps_data.id', 'gps_data.coordinate', 'gps_data.speed', 'gps_data.status', 'gps_data.date_time', 'gps_data.imei'])
            ->toBase()
            ->whereBetween('gps_data.date_time', [$start, $end])
            ->orderBy('gps_data.date_time')
            ->orderBy('gps_data.id');

        $records = [];

        foreach ($query->cursor() as $row) {
            if ($this->batch()?->cancelled()) {
                return 0;
            }
            // Resolve latitude / longitude for optional filtering
            $coordinate = $row->coordinate;
            if (is_string($coordinate)) {
                $parts = array_map('floatval', explode(',', $coordinate));
                $lat = $parts[0] ?? 0.0;
                $lon = $parts[1] ?? 0.0;
            } else {
                $lat = isset($coordinate[0]) ? (float)$coordinate[0] : 0.0;
                $lon = isset($coordinate[1]) ? (float)$coordinate[1] : 0.0;
            }

            $insideZone = true;
            if ($pointFilter !== null) {
                $insideZone = $pointFilter($lat, $lon);
            }

            $records[] = [
                'coordinate' => $coordinate,
                'speed' => $insideZone ? (float)$row->speed : 0.0,
                'status' => $insideZone ? (int)$row->status : 0,
                'date_time' => $row->date_time,
                'imei' => $row->imei ?? null,
            ];
        }

        if (empty($records)) {
            return 0;
        }

        /** @var GpsDataAnalyzer $analyzer */
        $analyzer = app(GpsDataAnalyzer::class);
        $analyzer->loadFromRecords($records);

        $results = $analyzer->analyzeLight($start, $end);

        return (int) ($results['movement_duration_seconds'] ?? 0);
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Tractor efficiency job failed permanently', [
            'tractor_id' => $this->tractorId,
            'date' => $this->dateString,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}

