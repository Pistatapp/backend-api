<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\Irrigation;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class IrrigationService
{
    public function __construct(
        private IrrigationReportCalculationService $calculator,
    ) {}

    /**
     * Get filtered list of irrigations for a farm.
     *
     * @param Farm $farm
     * @param string $status
     * @param string|null $dateRange
     * @param string|null $date
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getFilteredIrrigations(Farm $farm, string $status = 'all', ?string $dateRange = null, ?string $date = null, int $perPage = 15): LengthAwarePaginator
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
            ->paginate($perPage);
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
        $plot->load(['valves', 'field']);
        $irrigation->load(['valves', 'plots']);

        // GIS geometry remains physical-area metadata only. Irrigation
        // intensity uses the configured valve coverage in hectares.
        $physicalAreaM2 = $this->calculator->polygonArea($plot->coordinates);
        $physicalAreaHa = $physicalAreaM2 / 10000;

        // Get tree count
        $treeCount = $this->calculator->treesInsidePlot($plot);

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
        $irrigationDuration = $this->calculator->durationSeconds(
            $irrigation->start_time,
            $irrigation->end_time,
        );

        // Calculate irrigation volume and area metrics
        $volumeMetrics = $this->calculateVolumeMetrics($irrigation, $irrigationValves);

        return [
            'id' => $plot->id,
            'name' => $plot->name,
            'area' => $physicalAreaM2,
            'physical_area_m2' => $physicalAreaM2,
            'physical_area_ha' => $physicalAreaHa,
            'irrigation_area_ha' => $this->calculator->irrigatedAreaHectares($irrigationValves),
            'area_source' => 'valve.irrigation_area',
            'tree_count' => $treeCount,
            'latest_successful_irrigation' => $latestSuccessfulIrrigationData,
            'total_valve_count' => $valveStatistics['total_count'],
            'total_dripper_count' => $valveStatistics['total_dripper_count'],
            'dripper_flow_rate' => round($valveStatistics['dripper_flow_rate'], 2),
            // Compatibility key for the configured irrigation coverage.
            'irrigation_area' => round(
                $this->calculator->irrigatedAreaHectares($irrigationValves),
                4,
            ),
            'irrigation_duration' => to_time_format($irrigationDuration),
            // Keep the legacy key semantically correct: it is an area, not a volume.
            'total_irrigation_area' => round(
                $this->calculator->irrigatedAreaHectares($irrigationValves),
                4,
            ),
            'total_volume_m3' => $volumeMetrics['total_volume'],
            'irrigation_area_per_hectare' => $volumeMetrics['total_volume_per_hectare'] === null
                ? null
                : round($volumeMetrics['total_volume_per_hectare'], 2),
            'volume_m3_per_hectare' => $volumeMetrics['total_volume_per_hectare'],
        ];
    }

    /**
     * Get irrigation messages for a farm.
     *
     * @param Farm $farm
     * @param User $user
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getIrrigationMessages(Farm $farm, bool $isVerified, User $user, int $perPage = 15)
    {
        $irrigations = $farm->irrigations()
            ->where('status', 'finished')
            ->where('is_verified_by_admin', $isVerified)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereColumn('end_time', '>', 'start_time')
            // A completed irrigation review is private workflow data.  The
            // farm route is authenticated, but farm membership alone is not
            // sufficient to expose another operator's review message.
            ->where(function ($query) use ($user) {
                $query->where('created_by', $user->id)
                    ->orWhereHas('farm.admins', function ($adminQuery) use ($user) {
                        $adminQuery->whereKey($user->id);
                    });
            })
            ->with(['plots', 'valves'])
            ->latest()
            ->paginate($perPage);

        $pagination = [
            'total' => $irrigations->total(),
            'per_page' => $irrigations->perPage(),
            'current_page' => $irrigations->currentPage(),
            'last_page' => $irrigations->lastPage(),
            'from' => $irrigations->firstItem(),
            'to' => $irrigations->lastItem(),
        ];

        $irrigations = $irrigations->map(function ($irrigation) use ($user) {
            return $this->formatIrrigationMessage($irrigation, $user);
        });

        return [
            'data' => $irrigations,
            'pagination' => $pagination,
        ];
    }

    /**
     * Format irrigation data for message response.
     */
    private function formatIrrigationMessage(Irrigation $irrigation, User $user): array
    {
        $irrigation->loadMissing('plots');
        $physicalAreaM2 = $this->calculator->physicalAreaForPlots($irrigation->plots);
        $irrigatedAreaHa = $this->calculator->irrigatedAreaHectares($irrigation->valves);
        $volumeMetrics = $this->calculateVolumeMetrics($irrigation, $irrigation->valves);
        $lifecycle = app(\App\Services\IrrigationLifecycleService::class);

        return [
            'irrigation_id' => $irrigation->id,
            'status' => $irrigation->status,
            'is_verified_by_admin' => $irrigation->is_verified_by_admin,
            'date' => jdate($irrigation->start_time)->format('Y/m/d'),
            'plots_names' => $irrigation->plots->pluck('name')->toArray(),
            'valves_names' => $irrigation->valves->pluck('name')->toArray(),
            'duration' => to_time_format($volumeMetrics['duration']),
            'area_covered' => $irrigatedAreaHa,
            'physical_area_m2' => $physicalAreaM2,
            'physical_area_ha' => $physicalAreaM2 / 10000,
            'irrigation_area_ha' => $irrigatedAreaHa,
            'area_source' => 'valve.irrigation_area',
            'irrigation_per_hectare' => $volumeMetrics['total_volume_per_hectare'] === null
                ? null
                : round($volumeMetrics['total_volume_per_hectare'], 2),
            'total_volume' => round($volumeMetrics['total_volume'], 2),
            'lifecycle' => $lifecycle->payload($irrigation),
            'can' => [
                'update' => $user->can('update', $irrigation),
                'verify' => $user->can('verify', $irrigation),
                'operator_confirm' => $user->can('confirmOperator', $irrigation),
                'admin_confirm' => $user->can('verify', $irrigation),
                'operator_edit' => $lifecycle->canOperatorEdit($irrigation),
                'admin_edit' => $lifecycle->canAdminEdit($irrigation),
            ]
        ];
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
            ->verifiedByAdmin()
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereColumn('end_time', '>', 'start_time')
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
            ? round($totalDripperFlowRate / $irrigationValves->count(), 2)
            : 0.0;
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
    private function calculateVolumeMetrics(
        Irrigation $irrigation,
        Collection $irrigationValves,
    ): array
    {
        $durationInSeconds = $this->calculator->durationSeconds(
            $irrigation->start_time,
            $irrigation->end_time,
        );

        $totalVolumeLiters = $this->calculator->volumeLiters($irrigationValves, $durationInSeconds);
        $totalVolumeCubicMeters = $totalVolumeLiters / 1000;
        $irrigatedAreaHa = $this->calculator->irrigatedAreaHectares($irrigationValves);
        $totalVolumePerHectare = $this->calculator->volumePerHectareFromHa(
            $totalVolumeCubicMeters,
            $irrigatedAreaHa,
        );

        return [
            'duration' => $durationInSeconds,
            'total_volume' => $totalVolumeCubicMeters,
            'total_volume_per_hectare' => $totalVolumePerHectare,
        ];
    }

    /**
     * Calculate total irrigation volume in liters for the given valves and duration.
     */
    public function calculateVolumeLiters(iterable $valves, int $durationInSeconds): float
    {
        return $this->calculator->volumeLiters($valves, $durationInSeconds);
    }

    /**
     * Sum irrigation areas in hectares for the given valves.
     */
    public function calculateAreaHectares(iterable $valves): float
    {
        $totalIrrigationArea = 0;

        foreach ($valves as $valve) {
            $totalIrrigationArea += $valve->irrigation_area;
        }

        return $totalIrrigationArea;
    }

    /**
     * Calculate irrigation volume per hectare in cubic meters per hectare (m³/ha).
     *
     * Formula: (total liters / sum of areas in hectares) / 1000
     */
    public function calculateVolumePerHectareFromTotals(float $totalVolumeLiters, float $totalIrrigationAreaHectares): float
    {
        if ($totalIrrigationAreaHectares <= 0) {
            return 0;
        }

        return ($totalVolumeLiters / $totalIrrigationAreaHectares) / 1000;
    }

    /**
     * Calculate irrigation volume per hectare in cubic meters per hectare (m³/ha).
     */
    public function calculateVolumePerHectare(iterable $valves, int $durationInSeconds): float
    {
        return $this->calculateVolumePerHectareFromTotals(
            $this->calculateVolumeLiters($valves, $durationInSeconds),
            $this->calculateAreaHectares($valves)
        );
    }
}
