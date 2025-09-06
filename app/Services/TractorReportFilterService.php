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
        $accumulated = $this->calculateAccumulatedValues($this->tasks);
        // Get raw work duration for calculations
        $rawWorkDuration = $this->tasks->sum('work_duration');
        $expectations = $this->calculateExpectations($rawWorkDuration, $tractor, $filters);

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
                'traveled_distance' => $this->formatDistance($report->traveled_distance ?? 0),
                'avg_speed' => $this->formatSpeed($report->average_speed ?? 0),
                'work_duration' => $this->formatDuration($report->work_duration ?? 0),
                'stoppage_duration' => $this->formatDuration($report->stoppage_duration ?? 0),
                'stoppage_count' => (int) ($report->stoppage_count ?? 0),
                // New task data aggregates
                'consumed_water' => $this->formatVolume(data_get($task?->data, 'consumed_water', 0)),
                'consumed_fertilizer' => $this->formatWeight(data_get($task?->data, 'consumed_fertilizer', 0)),
                'consumed_poison' => $this->formatVolume(data_get($task?->data, 'consumed_poison', 0)),
                'operation_area' => $this->formatArea(data_get($task?->data, 'operation_area', 0)),
                'workers_count' => (int) data_get($task?->data, 'workers_count', 0),
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
        // Get raw values for calculations from the original model objects
        $rawReports = $reports->map(function ($report) {
            $task = $report->tractorTask;
            return [
                'traveled_distance' => $report->traveled_distance ?? 0,
                'avg_speed' => $report->average_speed ?? 0,
                'work_duration' => $report->work_duration ?? 0,
                'stoppage_duration' => $report->stoppage_duration ?? 0,
                'stoppage_count' => $report->stoppage_count ?? 0,
                'consumed_water' => data_get($task?->data, 'consumed_water', 0),
                'consumed_fertilizer' => data_get($task?->data, 'consumed_fertilizer', 0),
                'consumed_poison' => data_get($task?->data, 'consumed_poison', 0),
                'operation_area' => data_get($task?->data, 'operation_area', 0),
                'workers_count' => data_get($task?->data, 'workers_count', 0),
            ];
        });

        return [
            'traveled_distance' => $this->formatDistance($rawReports->sum('traveled_distance')),
            'avg_speed' => $this->formatSpeed($rawReports->avg('avg_speed')),
            'work_duration' => $this->formatDuration($rawReports->sum('work_duration')),
            'stoppage_duration' => $this->formatDuration($rawReports->sum('stoppage_duration')),
            'stoppage_count' => (int) $rawReports->sum('stoppage_count'),
            // New accumulated values from task data
            'consumed_water' => $this->formatVolume($rawReports->sum('consumed_water')),
            'consumed_fertilizer' => $this->formatWeight($rawReports->sum('consumed_fertilizer')),
            'consumed_poison' => $this->formatVolume($rawReports->sum('consumed_poison')),
            'operation_area' => $this->formatArea($rawReports->sum('operation_area')),
            'workers_count' => (int) $rawReports->sum('workers_count'),
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
            'expected_daily_work' => $this->formatDuration($dailyExpectedWork),
            'total_work_duration' => $this->formatDuration($totalWorkDuration),
            'total_efficiency' => $this->formatPercentage(min(100, $efficiency)),
        ];
    }

    /**
     * Format duration in seconds to H:i:s format.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            return '00:00:00';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Format speed in km/h with 2 decimal places.
     *
     * @param float $speed
     * @return string
     */
    private function formatSpeed(float $speed): string
    {
        return number_format($speed, 2);
    }

    /**
     * Format distance in kilometers with 2 decimal places.
     *
     * @param float $distance
     * @return string
     */
    private function formatDistance(float $distance): string
    {
        return number_format($distance, 2);
    }

    /**
     * Format volume in liters with 2 decimal places.
     *
     * @param float $volume
     * @return string
     */
    private function formatVolume(float $volume): string
    {
        return number_format($volume, 2);
    }

    /**
     * Format weight in kilograms with 2 decimal places.
     *
     * @param float $weight
     * @return string
     */
    private function formatWeight(float $weight): string
    {
        return number_format($weight, 2);
    }

    /**
     * Format area in square meters with 2 decimal places.
     *
     * @param float $area
     * @return string
     */
    private function formatArea(float $area): string
    {
        return number_format($area, 2);
    }

    /**
     * Format percentage with 2 decimal places.
     *
     * @param float $percentage
     * @return string
     */
    private function formatPercentage(float $percentage): string
    {
        return number_format($percentage, 2);
    }
}
