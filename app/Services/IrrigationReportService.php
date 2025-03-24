<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Irrigation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IrrigationReportService
{
    /**
     * Filter irrigation reports based on given criteria
     *
     * @param Farm $farm
     * @param array $filters
     * @return array
     */
    public function filterReports(Farm $farm, array $filters): array
    {
        $irrigations = $this->getFilteredIrrigations($farm, $filters);

        if (!isset($filters['field_id']) && !isset($filters['labour_id']) && !isset($filters['valve_id'])) {
            return $this->generateFarmIrrigationReports($farm, $filters['from_date'], $filters['to_date']);
        }

        return $this->generateIrrigationReports($irrigations, $filters['from_date'], $filters['to_date']);
    }

    /**
     * Get filtered irrigations query
     *
     * @param Farm $farm
     * @param array $filters
     * @return Collection
     */
    private function getFilteredIrrigations(Farm $farm, array $filters): Collection
    {
        return Irrigation::whereBelongsTo($farm)
            ->filter('finished')
            ->when($filters['field_id'] ?? null, function ($query) use ($filters) {
                $query->whereHas('fields', function ($query) use ($filters) {
                    $query->where('fields.id', $filters['field_id']);
                });
            })->when($filters['labour_id'] ?? null, function ($query) use ($filters) {
                $query->where('labour_id', $filters['labour_id']);
            })->when($filters['valve_id'] ?? null, function ($query) use ($filters) {
                $query->whereHas('valves', function ($query) use ($filters) {
                    $query->where('valves.id', $filters['valve_id']);
                });
            })->whereBetween('date', [$filters['from_date'], $filters['to_date']])
            ->with('fields', 'valves', 'labour', 'creator')
            ->get();
    }

    /**
     * Generate irrigation reports for filtered irrigations
     *
     * @param Collection $irrigations
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function generateIrrigationReports(Collection $irrigations, Carbon $startDate, Carbon $endDate): array
    {
        $irrigationReports = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $dailyIrrigations = $irrigations->filter(function ($irrigation) use ($currentDate) {
                return $irrigation->date->isSameDay($currentDate);
            });

            $totalDuration = 0;
            $totalVolume = 0;

            foreach ($dailyIrrigations as $irrigation) {
                $durationInMinutes = $irrigation->start_time->diffInMinutes($irrigation->end_time);
                $totalDuration += $durationInMinutes;

                foreach ($irrigation->valves as $valve) {
                    $totalVolume += $valve->flow_rate * $durationInMinutes;
                }
            }

            $irrigationReports[] = [
                'date' => jdate($currentDate)->format('Y/m/d'),
                'total_duration' => to_time_format($totalDuration),
                'total_volume' => $totalVolume,
                'irrigation_count' => $dailyIrrigations->count()
            ];

            $currentDate->addDay();
        }

        return $irrigationReports;
    }

    /**
     * Generate irrigation reports for the whole farm
     *
     * @param Farm $farm
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function generateFarmIrrigationReports(Farm $farm, Carbon $startDate, Carbon $endDate): array
    {
        $irrigationReports = [];
        $fields = $farm->fields;
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            $totalDuration = 0;
            $totalVolume = 0;
            $irrigationCount = 0;

            foreach ($fields as $field) {
                $dailyIrrigations = $field->irrigations()
                    ->whereDate('date', $currentDate)
                    ->with('valves')
                    ->get();

                foreach ($dailyIrrigations as $irrigation) {
                    $durationInMinutes = $irrigation->start_time->diffInMinutes($irrigation->end_time);
                    $totalDuration += $durationInMinutes;

                    foreach ($irrigation->valves as $valve) {
                        $totalVolume += $valve->flow_rate * $durationInMinutes;
                    }
                }

                $irrigationCount += $dailyIrrigations->count();
            }

            $irrigationReports[] = [
                'date' => jdate($currentDate)->format('Y/m/d'),
                'total_duration' => to_time_format($totalDuration),
                'total_volume' => $totalVolume,
                'irrigation_count' => $irrigationCount
            ];

            $currentDate->addDay();
        }

        return $irrigationReports;
    }
}
