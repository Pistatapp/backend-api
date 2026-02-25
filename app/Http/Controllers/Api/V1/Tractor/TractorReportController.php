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
        $reports = $tractor->reports()
            ->with(['operation', 'field'])
            ->latest()
            ->paginate();
        return TractorReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTractorReportRequest $request, Tractor $tractor)
    {
        $validated = $request->validated();
        $validated['tractor_id'] = $tractor->id;
        $validated['created_by'] = $request->user()->id;

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

        // Verify user has access to the tractor's farm
        $tractor = \App\Models\Tractor::findOrFail($validated['tractor_id']);
        if (!$tractor->farm->users->contains($request->user())) {
            abort(403, 'Unauthorized access to this tractor.');
        }

        $query = TractorReport::where('tractor_id', $validated['tractor_id'])
            ->with(['operation', 'field']);

        $filters = [
            'from_date',
            'to_date',
            'operation_id',
            'field_id',
        ];

        foreach ($filters as $filter) {
            if ($request->filled($filter)) {
                match ($filter) {
                    'from_date' => $query->where('date', '>=', $validated['from_date']),
                    'to_date' => $query->where('date', '<=', $validated['to_date']),
                    'operation_id' => $query->where('operation_id', $validated['operation_id']),
                    'field_id' => $query->where('field_id', $validated['field_id']),
                };
            }
        }

        $reports = $query->latest()->get();
        return TractorReportResource::collection($reports);
    }
}
