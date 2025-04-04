<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateTractorReportRequest;
use App\Models\Tractor;
use App\Models\TractorReport;
use Illuminate\Http\Request;
use App\Http\Resources\TractorReportResource;
use App\Http\Requests\StoreTractorReportRequest;
use App\Http\Requests\FilterTractorReportRequest;
use Illuminate\Support\Facades\Auth;

class TractorReportController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(TractorReport::class, 'tractor_report');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Tractor $tractor)
    {
        $reports = $tractor->reports()->with(['operation', 'field'])->latest()->simplePaginate();
        return TractorReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTractorReportRequest $request, Tractor $tractor)
    {
        $validated = $request->validated();
        $validated['tractor_id'] = $tractor->id;
        $validated['created_by'] = Auth::id();

        $report = TractorReport::create($validated);
        return new TractorReportResource($report->load(['operation', 'field']));
    }

    /**
     * Display the specified resource.
     */
    public function show(TractorReport $tractorReport)
    {
        return new TractorReportResource($tractorReport->load(['operation', 'field']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTractorReportRequest $request, TractorReport $tractorReport)
    {
        $validated = $request->validated();
        $tractorReport->update($validated);
        return new TractorReportResource($tractorReport->fresh(['operation', 'field']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TractorReport $tractorReport)
    {
        $tractorReport->delete();
        return response()->noContent();
    }

    /**
     * Filter tractor reports
     */
    public function filter(FilterTractorReportRequest $request)
    {
        $validated = $request->validated();
        $query = TractorReport::where('tractor_id', $validated['tractor_id'])
            ->with(['operation', 'field']);

        if (isset($validated['from_date'], $validated['to_date'])) {
            $query->whereBetween('date', [
                $validated['from_date'],
                $validated['to_date']
            ]);
        }

        if (isset($validated['operation_id'])) {
            $query->where('operation_id', $validated['operation_id']);
        }

        if (isset($validated['field_id'])) {
            $query->where('field_id', $validated['field_id']);
        }

        $reports = $query->latest()->get();
        return TractorReportResource::collection($reports);
    }
}
