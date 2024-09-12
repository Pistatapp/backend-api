<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIrrigationRequest;
use App\Http\Requests\UpdateIrrigationRequest;
use App\Http\Resources\IrrigationResource;
use App\Models\Field;
use App\Models\Irrigation;
use Illuminate\Http\Request;

class IrrigationController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Irrigation::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Field $field)
    {
        return IrrigationResource::collection(
            $field->irrigations()->latest()->simplePaginate()
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIrrigationRequest $request, Field $field)
    {
        $irrigation = $field->irrigations()->create([
            'labour_id' => $request->labour_id,
            'date' => $request->date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'valves' => $request->valves,
            'created_by' => $request->user()->id,
        ]);

        return new IrrigationResource($irrigation);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateIrrigationRequest $request, Irrigation $irrigation)
    {
        $irrigation->update($request->validated());

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
     * Filter reports by date range.
     */
    public function filterReports(Request $request, Field $field)
    {
        $request->validate([
            'from' => 'required|shamsi_date',
            'to' => 'required|shamsi_date|after_or_equal:from',
        ]);

        $from = jalali_to_carbon($request->from)->format('Y-m-d');
        $to = jalali_to_carbon($request->to)->format('Y-m-d');

        $irrigations = $field->irrigations()->whereBetween('date', [$from, $to])->get();

        $reports = $irrigations->map(function ($irrigation) use ($field) {
            $valve_info = $irrigation->valves()->map(function ($valve) use ($irrigation) {
                return [
                    'valve' => $valve->name,
                    'duration' => $irrigation->duration,
                    'volume' => number_format(time_to_hours($irrigation->duration) * $valve->flow_rate, 3),
                ];
            });

            return [
                'date' => jdate($irrigation->date)->format('Y/m/d'),
                'field_name' => $field->name,
                'labour_name' => $irrigation->labour->fname . ' ' . $irrigation->labour->lname,
                'valve_info' => $valve_info,
            ];
        });

        return response()->json(['data' => $reports]);
    }
}
