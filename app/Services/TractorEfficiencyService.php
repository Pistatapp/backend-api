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

    public function workingDurationSeconds(?int $movementSeconds): int
    {
        return max(0, (int) $movementSeconds);
    }

    /**
     * Read the explicit V3 accounting payload. Older rows use a conservative
     * compatibility projection and are never treated as all-stoppage work.
     */
    public function accountingForMetrics(GpsMetricsCalculation $metrics): array
    {
        $timings = is_array($metrics->timings) ? $metrics->timings : [];
        $accounting = is_array($timings['accounting'] ?? null) ? $timings['accounting'] : [];

        if ($accounting !== []) {
            return $accounting;
        }

        $moving = max(0, (int) $metrics->work_duration);
        $engineOnStop = max(0, (int) $metrics->stoppage_duration_while_on);

        return [
            'scheduled_duty_seconds' => null,
            'duty_moving_seconds' => $moving,
            'duty_engine_on_stopped_seconds' => $engineOnStop,
            'duty_work_seconds' => $moving + $engineOnStop,
            'overtime_seconds' => 0,
            'total_countable_work_seconds' => $moving + $engineOnStop,
            'unknown_gap_seconds' => null,
            'duty_fulfillment_percent' => null,
            'total_work_ratio_percent' => null,
            'policy_version' => 'legacy-compatibility',
        ];
    }

    public function dutyWorkDurationSeconds(GpsMetricsCalculation $metrics): int
    {
        return max(0, (int) ($this->accountingForMetrics($metrics)['duty_work_seconds'] ?? 0));
    }

    public function totalCountableWorkDurationSeconds(GpsMetricsCalculation $metrics): int
    {
        return max(0, (int) ($this->accountingForMetrics($metrics)['total_countable_work_seconds'] ?? 0));
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

    public function taskWorkingDurationSeconds(GpsMetricsCalculation $metrics): int
    {
        $timings = is_array($metrics->timings) ? $metrics->timings : [];

        return max(0, (int) ($timings['task_work_duration_seconds'] ?? $metrics->work_duration ?? 0));
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
            ->map(function (Collection $metrics, string $date) use ($dailyByDate): int {
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
            })
            ->sum();
    }

    /**
     * Return working time inside task zones using the union of canonical
     * working intervals. Legacy rows without intervals retain their stored
     * duration as a compatibility fallback.
     *
     * @param Collection<int, GpsMetricsCalculation> $taskMetrics
     * @param Collection<int, GpsMetricsCalculation> $dailyMetrics
     */
    public function taskWorkingDurationForPeriod(
        Collection $taskMetrics,
        ?Collection $dailyMetrics = null
    ): int {
        $dailyByDate = ($dailyMetrics ?? collect())
            ->sortBy(fn (GpsMetricsCalculation $metrics): int => (int) $metrics->getKey())
            ->groupBy(fn (GpsMetricsCalculation $metrics): string => $metrics->date->toDateString())
            ->map(fn (Collection $metrics): GpsMetricsCalculation => $metrics->first());

        return (int) $taskMetrics
            ->groupBy(fn (GpsMetricsCalculation $metrics): string => $metrics->date->toDateString())
            ->map(function (Collection $metrics, string $date) use ($dailyByDate): int {
                $workingSeconds = $this->taskWorkingDurationForDate($metrics);
                $dailyMetric = $dailyByDate->get($date);

                if ($dailyMetric) {
                    $workingSeconds = min(
                        $workingSeconds,
                        $this->totalCountableWorkDurationSeconds($dailyMetric)
                    );
                }

                return max(0, $workingSeconds);
            })
            ->sum();
    }

    /**
     * Merge task intervals for one date so overlapping tasks contribute once.
     * Rows created before canonical intervals were persisted use work_duration.
     *
     * @param Collection<int, GpsMetricsCalculation> $metrics
     */
    private function taskWorkingDurationForDate(Collection $metrics): int
    {
        $intervals = [];
        $legacySeconds = 0;

        foreach ($metrics as $metric) {
            $timings = is_array($metric->timings) ? $metric->timings : [];

            if (! array_key_exists('working_intervals', $timings)) {
                $legacySeconds += $this->taskWorkingDurationSeconds($metric);
                continue;
            }

            foreach ((array) $timings['working_intervals'] as $interval) {
                if (! is_array($interval)) {
                    continue;
                }

                $start = $interval['start'] ?? null;
                $end = $interval['end'] ?? null;
                if (! is_numeric($start) || ! is_numeric($end) || (int) $end <= (int) $start) {
                    continue;
                }

                $intervals[] = [(int) $start, (int) $end];
            }
        }

        return $this->unionIntervalSeconds($intervals) + $legacySeconds;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $intervals
     */
    private function unionIntervalSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }

        usort($intervals, function (array $left, array $right): int {
            return $left[0] <=> $right[0] ?: $left[1] <=> $right[1];
        });

        $total = 0;
        [$currentStart, $currentEnd] = $intervals[0];

        foreach (array_slice($intervals, 1) as [$start, $end]) {
            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);
                continue;
            }

            $total += $currentEnd - $currentStart;
            [$currentStart, $currentEnd] = [$start, $end];
        }

        return $total + ($currentEnd - $currentStart);
    }

    public function calculate(Tractor $tractor, int $durationSeconds): float
    {
        $expectedSeconds = $this->expectedDailyWorkSeconds($tractor);

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
            'persian_year' => $this->expectedDailyWorkSeconds($tractor)
                * max(0, $workingDays),
            default => $this->expectedDailyWorkSeconds($tractor),
        };

        if ($expectedSeconds <= 0) {
            return 0.0;
        }

        return ($durationSeconds / $expectedSeconds) * 100;
    }

    /**
     * Resolve the daily denominator from the tractor's configured schedule.
     *
     * A missing expected_daily_work_time is not permission to assume eight
     * hours: the schedule is the only safe fallback. If neither is configured,
     * the denominator remains zero and the caller reports no percentage.
     */
    public function expectedDailyWorkSeconds(Tractor $tractor): int
    {
        $configuredHours = $tractor->expected_daily_work_time;
        if (is_numeric($configuredHours) && (float) $configuredHours > 0) {
            return (int) round((float) $configuredHours * 3600);
        }

        $startTime = $tractor->start_work_time;
        $endTime = $tractor->end_work_time;
        if (! is_string($startTime) || trim($startTime) === ''
            || ! is_string($endTime) || trim($endTime) === '') {
            return 0;
        }

        try {
            $timezone = config('app.timezone', 'Asia/Tehran');
            $day = Carbon::today($timezone);
            $start = $day->copy()->setTimeFromTimeString($startTime);
            $end = $day->copy()->setTimeFromTimeString($endTime);

            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return max(0, $end->timestamp - $start->timestamp);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Calculate task productivity for one day from all task metric rows.
     * The cap prevents overlapping task rows from exceeding daily working
     * time.
     *
     * @param Collection<int, GpsMetricsCalculation> $taskMetrics
     */
    public function calculateTaskEfficiency(
        Tractor $tractor,
        Collection $taskMetrics,
        ?GpsMetricsCalculation $totalMetrics = null
    ): float {
        $workingSeconds = $this->taskWorkingDurationForPeriod(
            $taskMetrics,
            $totalMetrics ? collect([$totalMetrics]) : collect()
        );

        if ($totalMetrics) {
            $workingSeconds = min(
                $workingSeconds,
                $this->totalCountableWorkDurationSeconds($totalMetrics)
            );
        }

        return $this->calculate($tractor, max(0, $workingSeconds));
    }

    public function taskBasedEfficiencyForDate(Tractor $tractor, Carbon $date): float
    {
        $taskMetrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('tractor_task_id')
            ->get();

        $totalMetrics = GpsMetricsCalculation::where('tractor_id', $tractor->id)
            ->whereDate('date', $date->toDateString())
            ->whereNull('tractor_task_id')
            ->first();

        return $this->calculateTaskEfficiency($tractor, $taskMetrics, $totalMetrics);
    }
}
