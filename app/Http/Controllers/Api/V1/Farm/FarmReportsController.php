<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmReportRequest;
use App\Http\Requests\UpdateFarmReportRequest;
use App\Http\Resources\FarmReportResource;
use App\Models\Farm;
use App\Models\FarmReport;
use Illuminate\Http\Resources\Json\ResourceCollection;

class FarmReportsController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(FarmReport::class, 'farm_report');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm): ResourceCollection
    {
        $reports = $farm->reports()->latest()->simplePaginate();
        return FarmReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFarmReportRequest $request, Farm $farm): FarmReportResource
    {
        $validated = $request->validated();
        $reportable = getModel($validated['reportable_type'], $validated['reportable_id']);

        $farmReport = $reportable->reports()->create([
            'farm_id' => $farm->id,
            'date' => $validated['date'],
            'operation_id' => $validated['operation_id'],
            'labour_id' => $validated['labour_id'],
            'description' => $validated['description'],
            'value' => $validated['value'],
            'created_by' => $request->user()->id,
        ]);

        return new FarmReportResource($farmReport);
    }

    /**
     * Display the specified resource.
     */
    public function show(FarmReport $farmReport): FarmReportResource
    {
        return new FarmReportResource($farmReport->load(['operation', 'labour', 'reportable']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFarmReportRequest $request, FarmReport $farmReport): FarmReportResource
    {
        $validated = $request->validated();
        $reportable = getModel($validated['reportable_type'], $validated['reportable_id']);

        $farmReport->reportable()->associate($reportable);
        $farmReport->update([
            'date' => $validated['date'],
            'operation_id' => $validated['operation_id'],
            'labour_id' => $validated['labour_id'],
            'description' => $validated['description'],
            'value' => $validated['value'],
            'verified' => $validated['verified'] ?? $farmReport->verified,
        ]);

        return new FarmReportResource($farmReport->fresh(['operation', 'labour', 'reportable']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FarmReport $farmReport)
    {
        $farmReport->delete();
        return response()->noContent();
    }

    /**
     * Verify the specified farm report.
     */
    public function verify(FarmReport $farmReport)
    {
        $this->authorize('update', $farmReport);

        $farmReport->update(['verified' => true]);

        return new FarmReportResource($farmReport->fresh());
    }
}
