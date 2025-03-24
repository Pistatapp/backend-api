<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterIrrigationReportsRequest;
use App\Http\Requests\StoreIrrigationRequest;
use App\Http\Requests\UpdateIrrigationRequest;
use App\Http\Resources\IrrigationResource;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Irrigation;
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
    public function index(Farm $farm)
    {
        $irrigations = Irrigation::whereBelongsTo($farm)
            ->with('creator')->latest()->simplePaginate(10);
        return IrrigationResource::collection($irrigations);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIrrigationRequest $request, Farm $farm)
    {
        $irrigation = $farm->irrigations()->create([
            'labour_id' => $request->labour_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'created_by' => $request->user()->id,
            'note' => $request->note,
        ]);

        $irrigation->fields()->attach($request->fields);
        $irrigation->valves()->attach($request->valves);

        return new IrrigationResource($irrigation);
    }

    /**
     * Display the specified resource.
     */
    public function show(Irrigation $irrigation)
    {
        $irrigation->load(['labour', 'valves', 'creator', 'fields']);

        return new IrrigationResource($irrigation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIrrigationRequest $request, Irrigation $irrigation)
    {
        $irrigation->update([
            'labour_id' => $request->labour_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'note' => $request->note,
        ]);

        $irrigation->fields()->sync($request->fields);
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
     * Get irrigations for a field.
     *
     * @param  \App\Models\Field  $field
     * @return \App\Http\Resources\IrrigationResource
     */
    public function getIrrigationsForField(Field $field)
    {
        $irrigations = $field->irrigations()->with(['labour', 'valves', 'creator'])
            ->when(request()->has('date'), function ($query) {
                $query->where('date', jalali_to_carbon(request()->query('date')));
            }, function ($query) {
                $query->whereDate('date', today());
            })->latest()->get();

        return IrrigationResource::collection($irrigations);
    }

    /**
     * Get brief irrigation report for a field.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Field  $field
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationReportForField(Request $request, Field $field)
    {
        $date = $request->has('date') ? jalali_to_carbon($request->query('date')) : today();
        $irrigations = $field->irrigations()->filter('completed')->with('valves')
            ->whereDate('date', $date)
            ->get();

        $totalDuration = 0;
        $totalVolume = 0;

        foreach ($irrigations as $irrigation) {
            $durationInMinutes = $irrigation->start_time->diffInMinutes($irrigation->end_time);
            $totalDuration += $durationInMinutes;

            foreach ($irrigation->valves as $valve) {
                $totalVolume += $valve->flow_rate * $durationInMinutes;
            }
        }

        return response()->json([
            'data' => [
                'date' => jdate($date)->format('Y/m/d'),
                'total_duration' => to_time_format($totalDuration),
                'total_volume' => $totalVolume, // In liters
                'irrigation_count' => $irrigations->count(),
            ]
        ]);
    }

    /**
     * Filter reports by date range.
     */
    public function filterReports(FilterIrrigationReportsRequest $request, Farm $farm)
    {
        $filters = [
            'field_id' => $request->field_id,
            'labour_id' => $request->labour_id,
            'valve_id' => $request->valve_id,
            'from_date' => $request->from_date,
            'to_date' => $request->to_date,
        ];

        $reports = $this->irrigationReportService->filterReports($farm, $filters);

        return response()->json([
            'data' => $reports
        ]);
    }
}
