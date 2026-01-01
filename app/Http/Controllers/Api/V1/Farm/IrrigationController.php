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
use App\Services\IrrigationService;
use Illuminate\Http\Request;

class IrrigationController extends Controller
{
    public function __construct(
        private IrrigationReportService $irrigationReportService,
        private IrrigationService $irrigationService
    ) {
        $this->authorizeResource(Irrigation::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, Farm $farm)
    {
        $irrigations = $this->irrigationService->getFilteredIrrigations(
            $farm,
            $request->query('status', 'all'),
            $request->query('date_range'),
            $request->query('date')
        );

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
        $irrigation->load(['labour', 'valves', 'plots', 'pump']);

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
     * Get irrigation statistics for a plot within an irrigation.
     *
     * @param  \App\Models\Irrigation  $irrigation
     * @param  \App\Models\Plot  $plot
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationStatisticsForPlot(Irrigation $irrigation, Plot $plot)
    {
        // Verify the plot belongs to this irrigation
        if (!$irrigation->plots->contains($plot)) {
            return response()->json([
                'message' => 'Plot does not belong to this irrigation.'
            ], 404);
        }

        $statistics = $this->irrigationService->getStatisticsForPlot($irrigation, $plot);

        return response()->json([
            'data' => $statistics
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

        // Build aggregated daily reports to match API contract expected by tests
        $reports = $this->irrigationReportService->getAggregatedReports($plotIds, $filters);

        return response()->json([
            'data' => $reports
        ]);
    }

    /**
     * Verify the specified irrigation.
     */
    public function verify(Irrigation $irrigation)
    {
        $this->authorize('verify', $irrigation);

        $irrigation->forceFill([
            'is_verified_by_admin' => true,
        ])->save();

        return new IrrigationResource($irrigation->fresh());
    }

    /**
     * Get irrigation messages for a farm (finished irrigations of the day not verified by admin).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Farm  $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationMessages(Request $request, Farm $farm)
    {
        // Determine verified filter value: if verified parameter is present, use it (0=false, 1=true), otherwise default to false
        $isVerified = $request->has('verified')
            ? (bool) $request->query('verified')
            : false;

        $messages = $this->irrigationService->getIrrigationMessages($farm, $isVerified, $request->user());

        return response()->json([
            'data' => $messages
        ]);
    }
}
