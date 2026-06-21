<?php

namespace App\Jobs;

use App\Models\GpsMetricsCalculation;
use App\Models\TractorTask;
use App\Notifications\TractorTaskStatusNotification;
use App\Services\TaskGpsMetricsAnalyzer;
use App\Services\TractorTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class CalculateTaskGpsMetricsJob implements ShouldBeUnique, ShouldQueue
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
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->task->id;
    }

    /**
     * Execute the job.
     */
    public function handle(TaskGpsMetricsAnalyzer $gpsDataAnalyzer, TractorTaskService $tractorTaskService): void
    {
        $this->task->loadMissing('tractor.farm.admins');

        $taskStartTime = $this->task->getStartDateTime();
        $taskEndTime = $this->task->getEndDateTime();
        $tractor = $this->task->tractor;

        $taskZones = $tractorTaskService->getTaskZones($this->task);

        $results = $gpsDataAnalyzer->loadRecordsFor($tractor, $taskStartTime, $taskEndTime)
            ->analyze($taskZones);

        if (! $this->hasValidInZoneWork($results)) {
            $this->setTaskStatusAndNotify('not_done', null);

            return;
        }

        $efficiency = $this->calculateEfficiency($tractor, $results['movement_duration_seconds']);

        $timings = [
            'device_on_time' => $results['device_on_time'] ?? null,
            'first_movement_time' => $results['first_movement_time'] ?? null,
        ];

        $metrics = GpsMetricsCalculation::updateOrCreate(
            [
                'tractor_id' => $this->task->tractor_id,
                'tractor_task_id' => $this->task->id,
                'date' => $this->task->date->toDateString(),
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

        $this->setTaskStatusAndNotify('done', $metrics);
    }

    /**
     * Determine whether the tractor performed measurable work inside task zones.
     *
     * @param  array<string, mixed>  $results
     */
    private function hasValidInZoneWork(array $results): bool
    {
        if (! ($results['has_zone_presence'] ?? false)) {
            return false;
        }

        return ($results['movement_duration_seconds'] ?? 0) > 0
            || ($results['stoppage_duration_seconds'] ?? 0) > 0
            || ($results['movement_distance_km'] ?? 0) > 0;
    }

    /**
     * Calculate efficiency based on work duration.
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
     */
    private function setTaskStatusAndNotify(string $status, ?GpsMetricsCalculation $metrics): void
    {
        $this->task->update(['status' => $status]);

        $farm = $this->task->tractor->farm;

        if ($farm && $farm->admins) {
            Notification::send($farm->admins, new TractorTaskStatusNotification($this->task, $metrics));
        }
    }
}
