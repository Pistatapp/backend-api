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
        $date = $this->task->date;

        $dateString = $date->toDateString();

        // Get task time window
        $taskDate = Carbon::parse($this->task->date);
        $taskStartTime = $taskDate->copy()->setTimeFromTimeString($this->task->start_time);
        $taskEndTime = $taskDate->copy()->setTimeFromTimeString($this->task->end_time);

        // Ensure the tractor relationship is loaded
        $this->task->load('tractor');
        $tractor = $this->task->tractor;

        $taskZone = $tractorTaskService->getTaskZone($this->task);

        // Analyze GPS data with task time window
        $results = $gpsDataAnalyzer->loadRecordsFor($tractor, $date, $taskStartTime, $taskEndTime)
            ->analyze($taskZone);

        Log::info('Results: ' . json_encode($results));

        // Check if there's any valid GPS data
        if ($results['movement_duration_seconds'] <= 0) {
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

