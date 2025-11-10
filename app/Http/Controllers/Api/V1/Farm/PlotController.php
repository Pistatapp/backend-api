<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlotResource;
use App\Models\Plot;
use App\Models\Field;
use Illuminate\Http\Request;

class PlotController extends Controller
{
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

        $plot = $field->plots()->create($request->only([
            'name',
            'coordinates',
        ]));

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
        $thirtyDaysAgo = now()->subDays(30);

        // Get successful irrigations (status = 'finished') in the last 30 days
        $successfulIrrigations = $plot->irrigations()
            ->where('status', 'finished')
            ->where('start_date', '>=', $thirtyDaysAgo)
            ->with('valves')
            ->get();

        // Get latest successful irrigation
        $latestSuccessfulIrrigation = $plot->irrigations()
            ->where('status', 'finished')
            ->with('valves')
            ->latest('start_date')
            ->first();

        // Calculate statistics for last 30 days
        $totalDuration = 0;
        $totalVolume = 0;
        $totalAreaCovered = 0;

        foreach ($successfulIrrigations as $irrigation) {
            $durationInSeconds = $irrigation->start_time->diffInSeconds($irrigation->end_time);
            $totalDuration += $durationInSeconds;

            foreach ($irrigation->valves as $valve) {
                // Calculate volume for this valve
                $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $totalVolume += $volume;

                // Sum area covered
                $totalAreaCovered += $valve->irrigation_area;
            }
        }

        // Calculate total volume per hectare
        $totalVolumePerHectare = $totalAreaCovered > 0 ? $totalVolume / $totalAreaCovered : 0;

        // Format latest successful irrigation if exists
        $latestIrrigationData = null;
        if ($latestSuccessfulIrrigation) {
            $latestIrrigationData = [
                'id' => $latestSuccessfulIrrigation->id,
                'start_date' => jdate($latestSuccessfulIrrigation->start_date)->format('Y/m/d'),
                'end_date' => $latestSuccessfulIrrigation->end_date ? jdate($latestSuccessfulIrrigation->end_date)->format('Y/m/d') : null,
                'start_time' => $latestSuccessfulIrrigation->start_time->format('H:i'),
                'end_time' => $latestSuccessfulIrrigation->end_time->format('H:i'),
            ];
        }

        return response()->json([
            'data' => [
                'plot_name' => $plot->name,
                'latest_successful_irrigation' => $latestIrrigationData,
                'successful_irrigations_count_last_30_days' => $successfulIrrigations->count(),
                'area_covered_duration_last_30_days' => to_time_format($totalDuration),
                'total_volume_last_30_days' => round($totalVolume, 2),
                'total_volume_per_hectare_last_30_days' => round($totalVolumePerHectare, 2),
            ]
        ]);
    }
}
