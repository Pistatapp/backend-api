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

    /**
     * Return task presence for a report period without counting the same
     * observed interval twice when tasks overlap on a day.
     *
     * @param Collection<int, GpsMetricsCalculation> $taskMetrics
     * @param Collection<int, GpsMetricsCalculation> $dailyMetrics
     */
    public function taskPresenceDurationForPeriod(
        Collection $taskMetrics,
        ?Collection $dailyMetrics = null
    ): int {
        $dailyByDate = ($dailyMetrics ?? collect())
            ->sortBy(fn (GpsMetricsCalculation $metrics): int => (int) $metrics->getKey())
            ->groupBy(fn (GpsMetricsCalculation $metrics): string => $metrics->date->toDateString())
            ->map(fn (Collection $metrics): GpsMetricsCalculation => $metrics->first());

        return (int) $taskMetrics
            ->groupBy(fn (GpsMetricsCalculation $metrics): string => $metrics->date->toDateString())
            ->sum(function (Collection $metrics, string $date) use ($dailyByDate): int {
                $presenceSeconds = (int) $metrics->sum(
                    fn (GpsMetricsCalculation $metric): int => $this->taskPresenceDurationSeconds($metric)
                );
                $dailyMetric = $dailyByDate->get($date);

                if ($dailyMetric) {
                    $presenceSeconds = min(
                        $presenceSeconds,
                        $this->observedWorkDurationSeconds(
                            $dailyMetric->work_duration,
                            $dailyMetric->stoppage_duration
                        )
                    );
                }

                return max(0, $presenceSeconds);
            });
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
     * Calculate productivity using the configured denominator for a report
     * period. Daily reports use the same denominator as tractor details.
     */
    public function calculateForPeriod(
        Tractor $tractor,
        int $durationSeconds,
        ?string $period = null,
        int $workingDays = 0
    ): float {
        $expectedSeconds = match ($period) {
            'month', 'specific_month' => ((float) ($tractor->expected_monthly_work_time ?? 0)) * 3600,
            'year' => ((float) ($tractor->expected_yearly_work_time ?? 0)) * 3600,
            'persian_year' => ((float) ($tractor->expected_daily_work_time ?? 8))
                * 3600 * max(0, $workingDays),
            default => ((float) ($tractor->expected_daily_work_time ?? 8)) * 3600,
        };

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
        $presenceSeconds = $this->taskPresenceDurationForPeriod(
            $taskMetrics,
            $totalMetrics ? collect([$totalMetrics]) : collect()
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
