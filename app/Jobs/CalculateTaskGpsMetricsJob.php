<?php

namespace App\Jobs;

use App\Models\TractorTask;
use App\Models\GpsMetricsCalculation;
use App\Notifications\TractorTaskStatusNotification;
use App\Services\GpsDataAnalyzer;
use App\Services\TractorTaskService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CalculateTaskGpsMetricsJob implements ShouldQueue
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
    public function __construct(
        public TractorTask $task
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(GpsDataAnalyzer $gpsDataAnalyzer, TractorTaskService $tractorTaskService): void
    {
        $date = $this->task->date instanceof Carbon
            ? $this->task->date
            : Carbon::parse($this->task->date);

        $dateString = $date->toDateString();

        // Get task time window
        $taskDateTime = Carbon::parse($this->task->date);
        $taskStartDateTime = $taskDateTime->copy()->setTimeFromTimeString($this->task->start_time);
        $taskEndDateTime = $taskDateTime->copy()->setTimeFromTimeString($this->task->end_time);

        if ($taskEndDateTime->lt($taskStartDateTime)) {
            $taskEndDateTime->addDay();
        }

        // Ensure the tractor relationship is loaded
        $this->task->load('tractor');
        $tractor = $this->task->tractor;

        if (!$tractor) {
            Log::warning('No tractor found for task', [
                'task_id' => $this->task->id,
            ]);
            // If no tractor, set status to not_done and send notification
            $this->setTaskStatusAndNotify('not_done', null);
            return;
        }

        // Get GPS data for the tractor within task time window
        $gpsData = $tractor->gpsData()
            ->whereDate('date_time', $date)
            ->whereBetween('date_time', [$taskStartDateTime, $taskEndDateTime])
            ->orderBy('date_time')
            ->get();

        // Filter points that are in the task zone
        $taskZone = $tractorTaskService->getTaskZone($this->task);

        if (!$taskZone) {
            Log::warning('No task zone found for task', [
                'task_id' => $this->task->id,
                'tractor_id' => $this->task->tractor_id,
            ]);
            // If no task zone, set status to not_done and send notification
            $this->setTaskStatusAndNotify('not_done', null);
            return;
        }

        $filteredGpsData = $gpsData->filter(function ($point) use ($taskZone) {
            return is_point_in_polygon($point->coordinate, $taskZone);
        });

        if ($filteredGpsData->isEmpty()) {
            // If no GPS data in zone, set status to not_done and send notification
            $this->setTaskStatusAndNotify('not_done', null);
            return;
        }

        // Analyze GPS data with task time window
        $results = $gpsDataAnalyzer->loadFromRecords($filteredGpsData)->analyzeLight($taskStartDateTime, $taskEndDateTime);

        // Check if there's any valid GPS data
        if (empty($results['start_time'])) {
            // If no valid GPS data, set status to not_done and send notification
            $this->setTaskStatusAndNotify('not_done', null);
            return;
        }

        // Calculate efficiency
        $efficiency = $this->calculateEfficiency($tractor, $results['movement_duration_seconds']);

        // Build timings array from analyzer results
        $timings = [
            'device_on_time' => $results['device_on_time'] ?? null,
            'first_movement_time' => $results['first_movement_time'] ?? null,
        ];

        $taskStatus = 'done';

        // Update or create metrics record
        $metrics = GpsMetricsCalculation::updateOrCreate(
            [
                'tractor_id' => $this->task->tractor_id,
                'tractor_task_id' => $this->task->id,
                'date' => $dateString,
            ],
            [
                'traveled_distance' => $results['movement_distance_km'],
                'work_duration' => $results['movement_duration_seconds'],
                'stoppage_count' => $results['stoppage_count'],
                'stoppage_duration' => $results['stoppage_duration_seconds'],
                'stoppage_duration_while_on' => $results['stoppage_duration_while_on_seconds'],
                'stoppage_duration_while_off' => $results['stoppage_duration_while_off_seconds'],
                'average_speed' => $results['average_speed'],
                'efficiency' => $efficiency,
                'timings' => $timings,
            ]
        );

        // Update task status and send notification
        $this->setTaskStatusAndNotify($taskStatus, $metrics);
    }

    /**
     * Calculate efficiency based on work duration.
     *
     * @param \App\Models\Tractor $tractor
     * @param int $workDurationSeconds
     * @return float
     */
    private function calculateEfficiency($tractor, int $workDurationSeconds): float
    {
        $expectedDailyWorkHours = $tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;

        if ($expectedDailyWorkSeconds <= 0) {
            return 0;
        }

        return ($workDurationSeconds / $expectedDailyWorkSeconds) * 100;
    }

    /**
     * Set task status and send notification to farm admins.
     *
     * @param string $status
     * @param GpsMetricsCalculation|null $metrics
     * @return void
     */
    private function setTaskStatusAndNotify(string $status, ?GpsMetricsCalculation $metrics): void
    {
        // Update task status
        $this->task->update(['status' => $status]);

        // Load relationships needed for notification
        $this->task->load('tractor.farm');

        // Send notification to farm admins
        if ($this->task->tractor->farm && $this->task->tractor->farm->admins) {
            $farmAdmins = $this->task->tractor->farm->admins;
            Notification::send($farmAdmins, new TractorTaskStatusNotification($this->task, $metrics));
        }
    }
}

