<?php

namespace App\Services;

use App\Models\GpsMetricsCalculation;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared duration and productivity rules for tractor details and reports.
 */
class TractorEfficiencyService
{
    public function observedWorkDurationSeconds(?int $movementSeconds, ?int $stoppageSeconds): int
    {
        return max(0, (int) $movementSeconds) + max(0, (int) $stoppageSeconds);
    }

    public function taskPresenceDurationSeconds(GpsMetricsCalculation $metrics): int
    {
        $timings = is_array($metrics->timings) ? $metrics->timings : [];

        if (array_key_exists('in_zone_duration_seconds', $timings)) {
            return max(0, (int) $timings['in_zone_duration_seconds']);
        }

        // Compatibility for task rows created before in-zone duration was
        // persisted explicitly.
        return $this->observedWorkDurationSeconds(
            $metrics->work_duration,
            $metrics->stoppage_duration
        );
    }

    public function calculate(Tractor $tractor, int $durationSeconds): float
    {
        $expectedSeconds = ((float) ($tractor->expected_daily_work_time ?? 8)) * 3600;

        if ($expectedSeconds <= 0) {
            return 0.0;
        }

        return ($durationSeconds / $expectedSeconds) * 100;
    }

    /**
     * Calculate task productivity for one day from all task metric rows.
     * The cap prevents overlapping task rows from counting the same observed
     * working interval twice.
     *
     * @param Collection<int, GpsMetricsCalculation> $taskMetrics
     */
    public function calculateTaskEfficiency(
        Tractor $tractor,
        Collection $taskMetrics,
        ?GpsMetricsCalculation $totalMetrics = null
    ): float {
        $presenceSeconds = (int) $taskMetrics->sum(
            fn (GpsMetricsCalculation $metrics): int => $this->taskPresenceDurationSeconds($metrics)
        );

        if ($totalMetrics) {
            $presenceSeconds = min(
                $presenceSeconds,
                $this->observedWorkDurationSeconds(
                    $totalMetrics->work_duration,
                    $totalMetrics->stoppage_duration
                )
            );
        }

        return $this->calculate($tractor, max(0, $presenceSeconds));
    }

    public function taskBasedEfficiencyForDate(Tractor $tractor, Carbon $date): float
    {
        $taskMetrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->where('date', $date->toDateString())
            ->whereNotNull('tractor_task_id')
            ->get();

        $totalMetrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->where('date', $date->toDateString())
            ->whereNull('tractor_task_id')
            ->first();

        return $this->calculateTaskEfficiency($tractor, $taskMetrics, $totalMetrics);
    }
}
