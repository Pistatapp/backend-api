<?php

namespace App\Services;

use App\Models\Tractor;
use Illuminate\Support\Collection;
use Morilog\Jalali\Jalalian;

class TractorReportFilterService
{
    private $tasks;

    /**
     * Filter tractor reports by tractor and date/period.
     *
     * @param array $filters
     * @return array
     */
    public function filter(array $filters): array
    {
        // Find the tractor or fail
        $tractor = Tractor::findOrFail($filters['tractor_id']);
        // Start query from GpsDailyReport, eager loading related task, operation, and taskable
        $query = $tractor->gpsDailyReports()->with(['tractorTask.operation', 'tractorTask.taskable']);

        // Apply date/period filters
        $this->applyDateFilters($query, $filters);
        // Apply operation filter if present
        $this->applyOperationFilter($query, $filters);

        // Get the filtered reports
        $this->tasks = $query->get();

        // Map to report format
        $reports = $this->mapReportsToArray($this->tasks);
        $accumulated = $this->calculateAccumulatedValues($reports);
        $expectations = $this->calculateExpectations($accumulated['work_duration'], $tractor, $filters);

        return [
            'reports' => $reports,
            'accumulated' => $accumulated,
            'expectations' => $expectations,
        ];
    }

    /**
     * Apply date filters to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    private function applyDateFilters($query, array $filters): void
    {
        if (isset($filters['date'])) {
            $date = jalali_to_carbon($filters['date']);
            $query->whereDate('date', $date);
        } elseif (isset($filters['period'])) {
            $this->applyPeriodFilter($query, $filters);
        }
    }

    /**
     * Apply period filters to the query.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    private function applyPeriodFilter($query, array $filters): void
    {
        switch ($filters['period']) {
            case 'month':
                $this->filterByGregorianMonth($query);
                break;
            case 'year':
                $this->filterByGregorianYear($query);
                break;
            case 'specific_month':
                $this->filterBySpecificMonth($query, $filters);
                break;
            case 'persian_year':
                $this->filterByPersianYear($query, $filters);
                break;
        }
    }

    /**
     * Apply operation filter to the query.
     *
     * If the operation filter is not set or is null, do not filter by operation_id.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    private function applyOperationFilter($query, array $filters): void
    {
        // Only filter if 'operation' is set and not null
        if (array_key_exists('operation', $filters) && !is_null($filters['operation'])) {
            // Filter by operation_id on the related tractorTask
            $query->whereHas('tractorTask', function ($q) use ($filters) {
                $q->where('operation_id', $filters['operation']);
            });
        }
    }

    /**
     * Filter by Gregorian month.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    private function filterByGregorianMonth($query): void
    {
        $now = now();
        $query->whereYear('date', $now->year)
              ->whereMonth('date', $now->month);
    }

    /**
     * Filter by Gregorian year.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    private function filterByGregorianYear($query): void
    {
        $now = now();
        // Use explicit >= and <= instead of whereBetween for date range
        $query->where('date', '>=', $now->startOfYear())
              ->where('date', '<=', $now->copy()->endOfYear());
    }

    /**
     * Filter by specific Jalali month.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    private function filterBySpecificMonth($query, array $filters): void
    {
        if (!isset($filters['month'])) {
            throw new \InvalidArgumentException('Month parameter is required for specific_month period');
        }

        $date = Jalalian::fromFormat('Y/m/d', $filters['month']);
        $startDate = Jalalian::fromFormat('Y/m/d', sprintf('%d/%02d/01', $date->getYear(), $date->getMonth()))->toCarbon();
        $endDate = Jalalian::fromFormat('Y/m/d', sprintf('%d/%02d/%02d', $date->getYear(), $date->getMonth(), $date->getMonthDays()))->toCarbon();

        // Use explicit >= and <= instead of whereBetween for date range
        $query->where('date', '>=', $startDate)
              ->where('date', '<=', $endDate);
    }

    /**
     * Filter by Persian year.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param array $filters
     */
    private function filterByPersianYear($query, array $filters): void
    {
        if (!isset($filters['year'])) {
            throw new \InvalidArgumentException('Year parameter is required for persian_year period');
        }

        $year = $filters['year'];
        $startDate = Jalalian::fromFormat('Y/m/d', $year . '/01/01')->toCarbon();

        // Get the last day of the Persian year (Esfand can be 29 or 30 days)
        $lastDayOfYear = Jalalian::fromFormat('Y/m/d', $year . '/12/01')->getMonthDays();
        $endDate = Jalalian::fromFormat('Y/m/d', sprintf('%d/12/%02d', $year, $lastDayOfYear))->toCarbon();

        // Use explicit >= and <= instead of whereBetween for date range
        $query->where('date', '>=', $startDate)
              ->where('date', '<=', $endDate);
    }

    /**
     * Map GpsDailyReports to report format.
     *
     * @param Collection $reports
     * @return Collection
     */
    private function mapReportsToArray(Collection $reports): Collection
    {
        return $reports->map(function ($report) {
            $task = $report->tractorTask;
            return [
                'date' => jdate($report->date)->format('Y/m/d'),
                'operation_name' => $task?->operation?->name,
                'field_name' => $task?->taskable?->name,
                'traveled_distance' => $report->traveled_distance ?? 0,
                'min_speed' => $report->min_speed ?? 0,
                'max_speed' => $report->max_speed ?? 0,
                'avg_speed' => $report->average_speed ?? 0,
                'work_duration' => $report->work_duration ?? 0,
                'stoppage_duration' => $report->stoppage_duration ?? 0,
                'stoppage_count' => $report->stoppage_count ?? 0,
                // New task data aggregates
                'consumed_water' => data_get($task?->data, 'consumed_water', 0),
                'consumed_fertilizer' => data_get($task?->data, 'consumed_fertilizer', 0),
                'consumed_poison' => data_get($task?->data, 'consumed_poison', 0),
                'operation_area' => data_get($task?->data, 'operation_area', 0),
                'workers_count' => data_get($task?->data, 'workers_count', 0),
            ];
        });
    }

    /**
     * Calculate accumulated values from reports.
     *
     * @param Collection $reports
     * @return array
     */
    private function calculateAccumulatedValues(Collection $reports): array
    {
        return [
            'traveled_distance' => $reports->sum('traveled_distance'),
            'min_speed' => $reports->min('min_speed'),
            'max_speed' => $reports->max('max_speed'),
            'avg_speed' => $reports->avg('avg_speed'),
            'work_duration' => $reports->sum('work_duration'),
            'stoppage_duration' => $reports->sum('stoppage_duration'),
            'stoppage_count' => $reports->sum('stoppage_count'),
            // New accumulated values from task data
            'consumed_water' => $reports->sum('consumed_water'),
            'consumed_fertilizer' => $reports->sum('consumed_fertilizer'),
            'consumed_poison' => $reports->sum('consumed_poison'),
            'operation_area' => $reports->sum('operation_area'),
            'workers_count' => $reports->sum('workers_count'),
        ];
    }

    /**
     * Calculate expectations based on work duration.
     *
     * @param int $totalWorkDuration
     * @param Tractor $tractor
     * @param array $filters
     * @return array
     */
    private function calculateExpectations(int $totalWorkDuration, Tractor $tractor, array $filters = []): array
    {
        $dailyExpectedWork = $tractor->expected_daily_work_time * 3600;
        $workingDays = count($this->tasks ?? []);

        // Calculate efficiency based on period type
        $efficiency = match ($filters['period'] ?? 'day') {
            'month', 'specific_month' => $totalWorkDuration / ($tractor->expected_monthly_work_time * 3600) * 100,
            'year' => $totalWorkDuration / ($tractor->expected_yearly_work_time * 3600) * 100,
            'persian_year' => $workingDays > 0 ? ($totalWorkDuration / ($dailyExpectedWork * $workingDays)) * 100 : 0,
            default => $totalWorkDuration / $dailyExpectedWork * 100, // For daily and operation views
        };

        return [
            'expected_daily_work' => $dailyExpectedWork,
            'total_work_duration' => $totalWorkDuration,
            'total_efficiency' => min(100, $efficiency),
        ];
    }
}
