<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlotResource;
use App\Models\Plot;
use App\Models\Field;
use App\Helpers\UniqueId;
use App\Services\IrrigationService;
use App\Services\IrrigationReportCalculationService;
use Illuminate\Http\Request;

class PlotController extends Controller
{
    public function __construct(
        private IrrigationService $irrigationService,
        private IrrigationReportCalculationService $calculator
    ) {
        $this->authorizeResource(Plot::class, 'plot');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Field $field)
    {
        return PlotResource::collection($field->plots);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Field $field)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array',
        ]);

        $plot = $field->plots()->create(array_merge(
            $request->only([
                'name',
                'coordinates',
            ]),
            UniqueId::makeForTable('plots')
        ));

        return new PlotResource($plot);
    }

    /**
     * Display the specified resource.
     */
    public function show(Plot $plot)
    {
        return new PlotResource($plot->load('attachments'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Plot $plot)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array',
        ]);

        $plot->update($request->only([
            'name',
            'coordinates',
        ]));

        return new PlotResource($plot->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Plot $plot)
    {
        $plot->delete();

        return response()->noContent();
    }

    /**
     * Get irrigation statistics for a plot.
     *
     * @param  \App\Models\Plot  $plot
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationStatistics(Plot $plot)
    {
        $plot->load(['valves', 'field']);
        $rangeEnd = now()->setTimezone(IrrigationReportCalculationService::TIMEZONE);
        $rangeStart = $rangeEnd->copy()->subDays(30);
        $physicalAreaM2 = $this->calculator->polygonArea($plot->coordinates);
        $irrigationAreaHa = $this->calculator->irrigatedAreaHectares($plot->valves);
        $treesCount = $this->calculator->treesInsidePlot($plot);

        // Include any completed, verified program that overlaps the window.
        $successfulIrrigations = $plot->irrigations()
            ->where('status', 'finished')
            ->verifiedByAdmin()
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->whereColumn('end_time', '>', 'start_time')
            ->where('start_time', '<', $rangeEnd)
            ->where('end_time', '>', $rangeStart)
            ->with(['valves' => fn ($query) => $query->where('plot_id', $plot->id)])
            ->get();

        // Get latest successful irrigation
        $latestSuccessfulIrrigation = $plot->irrigations()
            ->where('status', 'finished')
            ->verifiedByAdmin()
            ->whereNotNull('end_time')
            ->latest('start_time')
            ->first();

        // Calculate statistics for last 30 days
        $totalDuration = 0;
        $totalVolumeLiters = 0.0;

        foreach ($successfulIrrigations as $irrigation) {
            $durationInSeconds = $this->calculator->overlapSeconds(
                $irrigation->start_time,
                $irrigation->end_time,
                $rangeStart,
                $rangeEnd,
            );
            $totalDuration += $durationInSeconds;
            $totalVolumeLiters += $this->irrigationService->calculateVolumeLiters($irrigation->valves, $durationInSeconds);
        }

        $totalVolumeM3 = $totalVolumeLiters / 1000;
        $totalVolumePerHectare = $this->calculator->volumePerHectareFromHa(
            $totalVolumeM3,
            $irrigationAreaHa,
        );

        // Format latest successful irrigation if exists
        $latestIrrigationData = null;
        if ($latestSuccessfulIrrigation) {
            $latestIrrigationData = [
                'id' => $latestSuccessfulIrrigation->id,
                'start_date' => jdate($latestSuccessfulIrrigation->start_time)->format('Y/m/d'),
                'end_date' => $latestSuccessfulIrrigation->end_time?->format('Y/m/d'),
                'start_time' => $latestSuccessfulIrrigation->start_time->format('H:i'),
                'end_time' => $latestSuccessfulIrrigation->end_time?->format('H:i'),
            ];
        }

        return response()->json([
            'data' => [
                'plot_name' => $plot->name,
                'trees_count' => $treesCount,
                'latest_successful_irrigation' => $latestIrrigationData,
                'successful_irrigations_count_last_30_days' => $successfulIrrigations->count(),
                'area_covered_duration_last_30_days' => to_time_format($totalDuration),
                'total_volume_last_30_days' => round($totalVolumeM3, 2),
                'total_volume_per_hectare_last_30_days' => $totalVolumePerHectare === null
                    ? null
                    : round($totalVolumePerHectare, 2),
                'irrigation_area_ha' => $irrigationAreaHa,
                'physical_area_m2' => $physicalAreaM2,
                'physical_area_ha' => $physicalAreaM2 / 10000,
                'area_source' => 'valve.irrigation_area',
            ]
        ]);
    }
}
