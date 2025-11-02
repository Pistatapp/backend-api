<?php

namespace App\Jobs;

use App\Models\Tractor;
use App\Models\GpsMetricsCalculation;
use App\Services\GpsDataAnalyzer;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateGpsMetricsJob implements ShouldQueue
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
        public Tractor $tractor,
        public Carbon $date
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(GpsDataAnalyzer $gpsDataAnalyzer): void
    {
        $dateString = $this->date->toDateString();

        // Load GPS records for the entire day using tractor's work time window
        // This automatically uses start_work_time and end_work_time if set
        $results = $gpsDataAnalyzer->loadRecordsFor($this->tractor, $this->date)->analyzeLight();

        // Check if there's any GPS data for this day
        if (empty($results['start_time'])) {
            return;
        }

        // Calculate efficiency
        $efficiency = $this->calculateEfficiency($results['movement_duration_seconds']);

        // Update or create metrics record for the entire day
        GpsMetricsCalculation::updateOrCreate(
            [
                'tractor_id' => $this->tractor->id,
                'date' => $dateString,
                'tractor_task_id' => null, // No task, this is for the entire day
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
            ]
        );
    }

    /**
     * Calculate efficiency based on work duration.
     *
     * @param int $workDurationSeconds
     * @return float
     */
    private function calculateEfficiency(int $workDurationSeconds): float
    {
        $expectedDailyWorkHours = $this->tractor->expected_daily_work_time ?? 8;
        $expectedDailyWorkSeconds = $expectedDailyWorkHours * 3600;

        if ($expectedDailyWorkSeconds <= 0) {
            return 0;
        }

        return ($workDurationSeconds / $expectedDailyWorkSeconds) * 100;
    }
}
