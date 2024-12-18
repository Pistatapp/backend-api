<?php

namespace App\Http\Controllers\Api\V1\User\Trucktor;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrucktorReportResource;
use App\Models\Trucktor;
use App\Models\TrucktorReport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TrucktorReportController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(TrucktorReport::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Trucktor $trucktor)
    {
        $reports = $trucktor->reports()->latest()->simplePaginate();
        return TrucktorReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Trucktor $trucktor)
    {
        $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'operation_id' => 'required|exists:operations,id',
            'field_id' => 'required|exists:fields,id',
            'description' => 'nullable|string',
        ]);

        $trucktorReport = $trucktor->reports()->create([
            'date' => jalali_to_carbon($request->date),
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'operation_id' => $request->operation_id,
            'field_id' => $request->field_id,
            'description' => $request->description,
            'created_by' => $request->user()->id,
        ]);

        return new TrucktorReportResource($trucktorReport);
    }

    /**
     * Display the specified resource.
     */
    public function show(TrucktorReport $trucktorReport)
    {
        return new TrucktorReportResource($trucktorReport);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TrucktorReport $trucktorReport)
    {
        $request->validate([
            'date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'operation_id' => 'required|exists:operations,id',
            'field_id' => 'required|exists:fields,id',
            'description' => 'nullable|string',
        ]);

        $trucktorReport->update([
            'date' => jalali_to_carbon($request->date),
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'operation_id' => $request->operation_id,
            'field_id' => $request->field_id,
            'description' => $request->description,
        ]);

        return new TrucktorReportResource($trucktorReport->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TrucktorReport $trucktorReport)
    {
        $trucktorReport->delete();

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
            'trucktor_id' => 'nullable|exists:trucktors,id',
        ]);

        $reports = TrucktorReport::whereBetween('date', [
            jalali_to_carbon($request->from),
            jalali_to_carbon($request->to),
        ])
            ->when($request->has('operation_id'), function ($query) use ($request) {
                return $query->where('operation_id', $request->operation_id);
            })
            ->when($request->has('field_id'), function ($query) use ($request) {
                return $query->where('field_id', $request->field_id);
            })
            ->when($request->has('trucktor_id'), function ($query) use ($request) {
                return $query->where('trucktor_id', $request->trucktor_id);
            })
            ->latest()
            ->simplePaginate();

        return TrucktorReportResource::collection($reports);
    }
}
