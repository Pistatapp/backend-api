<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterIrrigationReportsRequest;
use App\Http\Requests\StoreIrrigationRequest;
use App\Http\Requests\UpdateIrrigationRequest;
use App\Http\Resources\IrrigationResource;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Irrigation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class IrrigationController extends Controller
{
    public function __construct()
    {
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

        return response()->json([], JsonResponse::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Irrigation $irrigation)
    {
        $irrigation->load(['labour', 'valves', 'creator']);

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

        return response()->json([], JsonResponse::HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Irrigation $irrigation)
    {
        $irrigation->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
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
     * @param  \App\Models\Field  $field
     * @return \Illuminate\Http\JsonResponse
     */
    public function getIrrigationReportForField(Field $field)
    {
        $irrigations = $field->irrigations()->filter('completed')->with('valves')
            ->when(request()->has('date'), function ($query) {
                $query->where('date', jalali_to_carbon(request()->query('date')));
            }, function ($query) {
                $query->whereDate('date', today());
            })->get();

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
        $irrigations = Irrigation::whereBelongsTo($farm)
            ->filter('completed')
            ->when($request->field_id, function ($query) use ($request) {
                $query->whereHas('fields', function ($query) use ($request) {
                    $query->where('fields.id', $request->field_id);
                });
            })->when($request->labour_id, function ($query) use ($request) {
                $query->where('labour_id', $request->labour_id);
            })->when($request->valve_id, function ($query) use ($request) {
                $query->whereHas('valves', function ($query) use ($request) {
                    $query->where('valves.id', $request->valve_id);
                });
            })->whereBetween('date', [$request->from_date, $request->to_date])
            ->with('fields', 'valves', 'labour', 'creator')->latest()->get();

        $irrigationReports = $this->generateIrrigationReports($irrigations, $request->from_date, $request->to_date);

        return response()->json([
            'data' => $irrigationReports
        ]);
    }

    /**
     * Generate irrigation reports.
     */
    private function generateIrrigationReports($irrigations, $startDate, $endDate)
    {
        $irrigationReports = [];

        while ($startDate->lte($endDate)) {
            $dailyIrrigations = $irrigations->filter(function ($irrigation) use ($startDate) {
                return $irrigation->date->isSameDay($startDate);
            });

            if ($dailyIrrigations->isEmpty()) {
                $startDate->addDay();
                continue;
            }

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
                'date' => jdate($startDate)->format('Y/m/d'),
                'total_duration' => to_time_format($totalDuration),
                'total_volume' => $totalVolume, // In liters
                'irrigation_count' => $dailyIrrigations->count(),
            ];

            $startDate->addDay();
        }

        return $irrigationReports;
    }
}
