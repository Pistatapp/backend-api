<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmReportRequest;
use App\Http\Requests\UpdateFarmReportRequest;
use App\Http\Resources\FarmReportResource;
use App\Models\Farm;
use App\Models\FarmReport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FarmReportsController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(FarmReport::class, 'farm_report');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $reports = $farm->reports->load('operation', 'reportable');

        return FarmReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFarmReportRequest $request, Farm $farm)
    {
        $reportable = getModel($request->reportable_type, $request->reportable_id);

        $farmReport = $reportable->reports()->create([
            'farm_id' => $farm->id,
            'date' => jalali_to_carbon($request->date),
            'operation_id' => $request->operation_id,
            'labour_id' => $request->labour_id,
            'description' => $request->description,
            'value' => $request->value,
        ]);

        return new FarmReportResource($farmReport);
    }

    /**
     * Display the specified resource.
     */
    public function show(FarmReport $farmReport)
    {
        $farmReport->load('operation', 'labour', 'reportable');
        return new FarmReportResource($farmReport);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFarmReportRequest $request, FarmReport $farmReport)
    {
        $farmReport->update([
            'date' => jalali_to_carbon($request->date),
            'operation_id' => $request->operation_id,
            'labour_id' => $request->labour_id,
            'description' => $request->description,
            'value' => $request->value,
        ]);

        return new FarmReportResource($farmReport->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FarmReport $farmReport)
    {
        $farmReport->delete();
        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
