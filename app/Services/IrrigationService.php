<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Irrigation;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Support\Collection;

class IrrigationService
{
    /**
     * Get filtered list of irrigations for a farm.
     *
     * @param Farm $farm
     * @param string $status
     * @param string|null $dateRange
     * @param string|null $date
     * @return Collection
     */
    public function getFilteredIrrigations(Farm $farm, string $status = 'all', ?string $dateRange = null, ?string $date = null): Collection
    {
        $query = Irrigation::whereBelongsTo($farm);

        // Handle date filtering
        if ($dateRange) {
            // Parse date range (format: "start_date,end_date")
            $dates = explode(',', $dateRange);
            if (count($dates) === 2) {
                $startDate = jalali_to_carbon(trim($dates[0]))->startOfDay();
                $endDate = jalali_to_carbon(trim($dates[1]))->endOfDay();

                // Get irrigations that span the date range (overlap with the range)
                // An irrigation overlaps if: start_time <= endDate AND (end_time >= startDate OR end_time IS NULL)
                $query->where('start_time', '<=', $endDate)
                    ->where(function ($q) use ($startDate) {
                        $q->where('end_time', '>=', $startDate)
                            ->orWhereNull('end_time');
                    });
            }
        } elseif ($date) {
            // Single date filter - get irrigations that span this date
            $dateCarbon = jalali_to_carbon($date);
            $startOfDay = $dateCarbon->copy()->startOfDay();
            $endOfDay = $dateCarbon->copy()->endOfDay();

            // An irrigation spans the date if: start_time <= endOfDay AND (end_time >= startOfDay OR end_time IS NULL)
            $query->where('start_time', '<=', $endOfDay)
                ->where(function ($q) use ($startOfDay) {
                    $q->where('end_time', '>=', $startOfDay)
                        ->orWhereNull('end_time');
                });
        } else {
            // Default to today - get irrigations that span today
            $startOfDay = today()->startOfDay();
            $endOfDay = today()->endOfDay();

            $query->where('start_time', '<=', $endOfDay)
                ->where(function ($q) use ($startOfDay) {
                    $q->where('end_time', '>=', $startOfDay)
                        ->orWhereNull('end_time');
                });
        }

        return $query->when($status !== 'all', function ($q) use ($status) {
                $q->filter($status);
            })
            ->with(['plots', 'valves'])
            ->withCount('plots')
            ->latest()
            ->get();
    }

    /**
     * Get irrigation statistics for a plot within an irrigation.
     *
     * @param Irrigation $irrigation
     * @param Plot $plot
     * @return array
     */
    public function getStatisticsForPlot(Irrigation $irrigation, Plot $plot): array
    {
        // Load relationships
        $plot->load(['valves', 'trees']);
        $irrigation->load('valves');

        // Calculate plot area
        $area = $plot->coordinates ? calculate_polygon_area($plot->coordinates) : 0;

        // Get tree count
        $treeCount = $plot->trees()->count();

        // Get latest successful irrigation for this plot
        $latestSuccessfulIrrigation = $this->getLatestSuccessfulIrrigation($plot);
        $latestSuccessfulIrrigationData = $latestSuccessfulIrrigation
            ? [
                'id' => $latestSuccessfulIrrigation->id,
                'date' => jdate($latestSuccessfulIrrigation->start_time)->format('Y/m/d'),
            ]
            : null;

        // Get valves for this plot that belong to this irrigation
        $irrigationValves = $irrigation->valves->where('plot_id', $plot->id);

        // Calculate valve statistics
        $valveStatistics = $this->calculateValveStatistics($plot, $irrigationValves);

        // Calculate irrigation duration
        $irrigationDuration = $irrigation->start_time->diffInSeconds(now());

        // Calculate irrigation volume and area metrics
        $volumeMetrics = $this->calculateVolumeMetrics($irrigation, $irrigationValves);

        return [
            'id' => $plot->id,
            'name' => $plot->name,
            'area' => $area,
            'tree_count' => $treeCount,
            'latest_successful_irrigation' => $latestSuccessfulIrrigationData,
            'total_valve_count' => $valveStatistics['total_count'],
            'total_dripper_count' => $valveStatistics['total_dripper_count'],
            'dripper_flow_rate' => round($valveStatistics['dripper_flow_rate'], 2),
            'irrigation_area' => round($valveStatistics['irrigation_area'], 2),
            'irrigation_duration' => to_time_format($irrigationDuration),
            'total_irrigation_area' => round($volumeMetrics['total_volume'], 2),
            'irrigation_area_per_hectare' => round($volumeMetrics['irrigation_area_per_hectare'], 2),
        ];
    }

    /**
     * Get irrigation messages for a farm.
     *
     * @param Farm $farm
     * @param bool $isVerified
     * @param User $user
     * @return Collection
     */
    public function getIrrigationMessages(Farm $farm, bool $isVerified, User $user)
    {
        $irrigations = Irrigation::whereBelongsTo($farm)
            ->filter('finished')
            ->verifiedByAdmin($isVerified)
            ->with(['plots', 'valves'])
            ->latest()
            ->get();

        $messages = $irrigations->map(function ($irrigation) use ($user) {
            $volumeMetrics = $this->calculateIrrigationVolumeMetrics($irrigation);

            return [
                'irrigation_id' => $irrigation->id,
                'date' => jdate($irrigation->date)->format('Y/m/d'),
                'plots_names' => $irrigation->plots->pluck('name')->toArray(),
                'valves_names' => $irrigation->valves->pluck('name')->toArray(),
                'duration' => to_time_format($volumeMetrics['duration']),
                'irrigation_per_hectare' => round($volumeMetrics['total_volume_per_hectare'], 2),
                'total_volume' => round($volumeMetrics['total_volume'], 2),
                'can' => [
                    'update' => $user->can('update', $irrigation),
                    'verify' => $user->can('verify', $irrigation),
                ]
            ];
        });

        return $messages;
    }

    /**
     * Get the latest successful irrigation for a plot.
     *
     * @param Plot $plot
     * @return Irrigation|null
     */
    private function getLatestSuccessfulIrrigation(Plot $plot): ?Irrigation
    {
        return $plot->irrigations()
            ->where('status', 'finished')
            ->latest('start_time')
            ->first();
    }

    /**
     * Calculate valve statistics for a plot.
     *
     * @param Plot $plot
     * @param Collection $irrigationValves
     * @return array
     */
    private function calculateValveStatistics(Plot $plot, Collection $irrigationValves): array
    {
        $totalValveCount = $plot->valves()->count();
        $totalDripperCount = $irrigationValves->sum('dripper_count');
        $totalDripperFlowRate = $irrigationValves->sum('dripper_flow_rate');
        $dripperFlowRate = $irrigationValves->count() > 0
            ? $totalDripperFlowRate / $irrigationValves->count()
            : 0;
        $irrigationArea = $irrigationValves->sum('irrigation_area');

        return [
            'total_count' => $totalValveCount,
            'total_dripper_count' => $totalDripperCount,
            'dripper_flow_rate' => $dripperFlowRate,
            'irrigation_area' => $irrigationArea,
        ];
    }

    /**
     * Calculate volume metrics for an irrigation.
     *
     * @param Irrigation $irrigation
     * @param Collection $irrigationValves
     * @return array
     */
    private function calculateVolumeMetrics(Irrigation $irrigation, Collection $irrigationValves): array
    {
        $durationInSeconds = $irrigation->start_time->diffInSeconds(
            $irrigation->end_time ?? now()
        );

        // Calculate total irrigation volume (in cubic meters)
        $totalVolume = 0;
        foreach ($irrigationValves as $valve) {
            $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
            $totalVolume += $volume;
        }
        $totalVolume /= 1000; // Convert to cubic meters

        // Calculate irrigation area per hectare (in cubic meters per hectare)
        $irrigationArea = $irrigationValves->sum('irrigation_area');
        $irrigationAreaPerHectare = $irrigationArea > 0
            ? ($totalVolume / ($irrigationArea / 10000))
            : 0; // Convert irrigation_area from square meters to hectares (divide by 10000)

        return [
            'total_volume' => $totalVolume,
            'irrigation_area_per_hectare' => $irrigationAreaPerHectare,
        ];
    }

    /**
     * Calculate volume metrics for an irrigation message.
     *
     * @param Irrigation $irrigation
     * @return array
     */
    private function calculateIrrigationVolumeMetrics(Irrigation $irrigation): array
    {
        $durationInSeconds = $irrigation->start_time->diffInSeconds($irrigation->end_time);
        $totalVolume = 0;
        $totalVolumePerHectare = 0;

        foreach ($irrigation->valves as $valve) {
            $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
            $totalVolume += $volume;
            if ($valve->irrigation_area > 0) {
                $totalVolumePerHectare += $volume / $valve->irrigation_area;
            }
        }

        $totalVolume = round($totalVolume / 1000, 2);
        $totalVolumePerHectare = round($totalVolumePerHectare / 1000, 2);

        return [
            'duration' => $durationInSeconds,
            'total_volume' => $totalVolume,
            'total_volume_per_hectare' => $totalVolumePerHectare,
        ];
    }
}
