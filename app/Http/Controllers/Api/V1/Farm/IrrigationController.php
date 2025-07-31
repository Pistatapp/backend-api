<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterIrrigationReportsRequest;
use App\Http\Requests\StoreIrrigationRequest;
use App\Http\Requests\UpdateIrrigationRequest;
use App\Http\Resources\IrrigationResource;
use App\Models\Farm;
use App\Models\Irrigation;
use App\Models\Plot;
use App\Services\IrrigationReportService;
use Illuminate\Http\Request;

class IrrigationController extends Controller
{
    public function __construct(
        private IrrigationReportService $irrigationReportService
    ) {
        $this->authorizeResource(Irrigation::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Farm $farm)
    {
        $date = $request->has('date') ? jalali_to_carbon($request->query('date')) : today();
        $status = $request->query('status', 'all');

        $irrigations = Irrigation::whereBelongsTo($farm)
            ->whereDate('date', $date)
            ->when($status !== 'all', function ($query) use ($status) {
                $query->filter($status);
            })->with('creator', 'plots')->latest()->get();
        return IrrigationResource::collection($irrigations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIrrigationRequest $request, Farm $farm)
    {
        $irrigation = $farm->irrigations()->create([
            'labour_id' => $request->labour_id,
            'pump_id' => $request->pump_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'created_by' => $request->user()->id,
            'note' => $request->note,
        ]);

        $irrigation->plots()->attach($request->plots);
        $irrigation->valves()->attach($request->valves);

        return new IrrigationResource($irrigation);
    }

    /**
     * Display the specified resource.
     */
    public function show(Irrigation $irrigation)
    {
        $irrigation->load(['labour', 'valves', 'creator', 'plots', 'pump']);

        return new IrrigationResource($irrigation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIrrigationRequest $request, Irrigation $irrigation)
    {
        $irrigation->update([
            'labour_id' => $request->labour_id,
            'pump_id' => $request->pump_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'note' => $request->note,
        ]);

        $irrigation->plots()->sync($request->plots);
        $irrigation->valves()->sync($request->valves);

        return new IrrigationResource($irrigation->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Irrigation $irrigation)
    {
        $irrigation->delete();

        return response()->noContent();
    }

    /**
     * Get irrigations for a plot.
     *
     * @param  \App\Models\Plot  $plot
     * @return \App\Http\Resources\IrrigationResource
     */
    public function getIrrigationsForPlot(Plot $plot)
    {
        $irrigations = $plot->irrigations()->with(['labour', 'valves', 'creator'])
            ->when(request()->has('date'), function ($query) {
                $query->where('date', jalali_to_carbon(request()->query('date')));
            }, function ($query) {
                $query->whereDate('date', today());
            })
            ->when(request()->has('status'), function ($query) {
                $query->filter(request()->query('status'));
            })
            ->latest()->get();

        return IrrigationResource::collection($irrigations);
    }

    /**
     * Get brief irrigation report for a plot.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Plot  $plot
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationReportForPlot(Request $request, Plot $plot)
    {
        $date = $request->has('date') ? jalali_to_carbon($request->query('date')) : today();
        $irrigations = $plot->irrigations()->filter('finished')->with('valves')->whereDate('date', $date)->get();

        $totalDuration = 0;
        $totalVolume = 0;
        $totalVolumePerHectare = 0;

        foreach ($irrigations as $irrigation) {
            $durationInSeconds = $irrigation->start_time->diffInSeconds($irrigation->end_time);
            $totalDuration += $durationInSeconds;

            foreach ($irrigation->valves as $valve) {
                $volume = ($valve->dripper_count * $valve->dripper_flow_rate) * ($durationInSeconds / 3600);
                $totalVolume += $volume;
                $totalVolumePerHectare += $volume / $valve->irrigation_area;
            }
        }

        return response()->json([
            'data' => [
                'date' => jdate($date)->format('Y/m/d'),
                'total_duration' => to_time_format($totalDuration),
                'total_volume' => $totalVolume,
                'total_volume_per_hectare' => $totalVolumePerHectare,
                'total_count' => $irrigations->count(),
            ]
        ]);
    }

    /**
     * Filter reports by date range.
     */
    public function filterReports(FilterIrrigationReportsRequest $request, Farm $farm)
    {
        $filters = [
            'labour_id' => $request->labour_id,
            'valves' => $request->valves,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
        ];

        $plotIds = $request->plot_ids;

        $reports = $this->irrigationReportService->filterReports($plotIds, $filters);

        return response()->json([
            'data' => $reports
        ]);
    }
}
