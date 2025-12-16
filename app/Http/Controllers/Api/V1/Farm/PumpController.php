<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Models\Pump;
use App\Http\Requests\StorePumpRequest;
use App\Http\Requests\UpdatePumpRequest;
use App\Http\Requests\PumpIrrigationReportRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\PumpResource;
use App\Models\Farm;
use App\Models\Irrigation;

class PumpController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->authorizeResource(Pump::class, 'pump');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return PumpResource::collection($farm->pumps);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePumpRequest $request, Farm $farm)
    {
        $pump = $farm->pumps()->create($request->validated());

        return new PumpResource($pump);
    }

    /**
     * Display the specified resource.
     */
    public function show(Pump $pump)
    {
        return new PumpResource($pump->load('attachments'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePumpRequest $request, Pump $pump)
    {
        $pump->update($request->validated());

        return new PumpResource($pump->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pump $pump)
    {
        $pump->delete();

        return response()->noContent();
    }

    /**
     * Generate irrigation report for a pump.
     */
    public function generateIrrigationReport(PumpIrrigationReportRequest $request, Pump $pump)
    {
        $startDate = $request->start_date->startOfDay();
        $endDate = $request->end_date->endOfDay();

        // Get irrigations for this pump within the date range
        // Filter based on start_time and end_time of irrigation
        $irrigations = Irrigation::where('pump_id', $pump->id)
            ->filter('finished')
            ->verifiedByAdmin()
            ->where(function ($query) use ($startDate, $endDate) {
                // Include irrigation if it overlaps with the date range
                // Overlap occurs when: irrigation.start_time <= endDate AND irrigation.end_time >= startDate
                $query->where('start_time', '<=', $endDate)
                    ->where('end_time', '>=', $startDate);
            })
            ->with('valves')
            ->get();

        // Generate daily reports
        $dailyReports = [];
        $currentDate = $startDate->copy();

        while ($currentDate->lte($endDate)) {
            // Filter irrigations active on this date
            $dailyIrrigations = $irrigations->filter(function ($irrigation) use ($currentDate) {
                // Check if irrigation has valid start_time and end_time
                if (!$irrigation->start_time || !$irrigation->end_time) {
                    return false;
                }

                // Get the date part of the irrigation start and end times
                $irrigationStartDate = $irrigation->start_time->copy()->startOfDay();
                $irrigationEndDate = $irrigation->end_time->copy()->startOfDay();
                $currentDateStart = $currentDate->copy()->startOfDay();

                // Check if the current date overlaps with the irrigation period
                // Irrigation is active on currentDate if: start_date <= currentDate <= end_date
                return $irrigationStartDate->lte($currentDateStart) &&
                       $irrigationEndDate->gte($currentDateStart);
            });

            // Only include dates with at least one irrigation
            if ($dailyIrrigations->count() > 0) {
                $totalDurationSeconds = 0;
                $totalVolume = 0; // in liters

                foreach ($dailyIrrigations as $irrigation) {
                    $durationInSeconds = $irrigation->start_time->diffInSeconds($irrigation->end_time);
                    $totalDurationSeconds += $durationInSeconds;

                    foreach ($irrigation->valves as $valve) {
                        $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                        $totalVolume += $volume;
                    }
                }

                // Convert volume from liters to cubic meters
                $totalVolume = round($totalVolume / 1000, 2);
                // Calculate hours from seconds
                $totalHours = $totalDurationSeconds / 3600;

                $dailyReports[] = [
                    'date' => jdate($currentDate)->format('Y/m/d'),
                    'hours' => round($totalHours, 2),
                    'volume' => $totalVolume,
                ];
            }

            $currentDate->addDay();
        }

        // Calculate accumulated values
        $accumulatedHours = 0;
        $accumulatedVolume = 0;

        foreach ($dailyReports as $report) {
            $accumulatedHours += $report['hours'];
            $accumulatedVolume += $report['volume'];
        }

        return response()->json([
            'data' => [
                'irrigations' => $dailyReports,
                'accumulated' => [
                    'hours' => round($accumulatedHours, 2),
                    'volume' => round($accumulatedVolume, 2),
                ],
            ],
        ]);
    }
}
