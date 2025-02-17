<?php

namespace App\Http\Controllers\Api\V1\tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\TractorReportResource;
use App\Models\Tractor;
use App\Models\TractorReport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TractorReportController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(TractorReport::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Tractor $tractor)
    {
        $reports = $tractor->reports()->latest()->simplePaginate();
        return TractorReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Tractor $tractor)
    {
        $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'operation_id' => 'required|exists:operations,id',
            'field_id' => 'required|exists:fields,id',
            'description' => 'nullable|string',
        ]);

        $tractorReport = $tractor->reports()->create([
            'date' => jalali_to_carbon($request->date),
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'operation_id' => $request->operation_id,
            'field_id' => $request->field_id,
            'description' => $request->description,
            'created_by' => $request->user()->id,
        ]);

        return new TractorReportResource($tractorReport);
    }

    /**
     * Display the specified resource.
     */
    public function show(TractorReport $tractorReport)
    {
        return new TractorReportResource($tractorReport);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TractorReport $tractorReport)
    {
        $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'operation_id' => 'required|exists:operations,id',
            'field_id' => 'required|exists:fields,id',
            'description' => 'nullable|string',
        ]);

        $tractorReport->update([
            'date' => jalali_to_carbon($request->date),
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'operation_id' => $request->operation_id,
            'field_id' => $request->field_id,
            'description' => $request->description,
        ]);

        return new TractorReportResource($tractorReport->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TractorReport $tractorReport)
    {
        $tractorReport->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }

    /**
     * Filter the specified resource from storage.
     */
    public function filter(Request $request)
    {
        $request->validate([
            'from' => 'required|shamsi_date',
            'to' => 'required|shamsi_date',
            'operation_id' => 'nullable|exists:operations,id',
            'field_id' => 'nullable|exists:fields,id',
            'tractor_id' => 'nullable|exists:tractors,id',
        ]);

        $reports = TractorReport::whereBetween('date', [
            jalali_to_carbon($request->from),
            jalali_to_carbon($request->to),
        ])
            ->when($request->has('operation_id'), function ($query) use ($request) {
                return $query->where('operation_id', $request->operation_id);
            })
            ->when($request->has('field_id'), function ($query) use ($request) {
                return $query->where('field_id', $request->field_id);
            })
            ->when($request->has('tractor_id'), function ($query) use ($request) {
                return $query->where('tractor_id', $request->tractor_id);
            })
            ->latest()
            ->simplePaginate();

        return TractorReportResource::collection($reports);
    }
}
