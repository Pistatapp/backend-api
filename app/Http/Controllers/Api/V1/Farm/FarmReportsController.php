<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmReportRequest;
use App\Http\Requests\UpdateFarmReportRequest;
use App\Http\Requests\FarmReportFilterRequest;
use App\Http\Resources\FarmReportResource;
use App\Models\Farm;
use App\Models\FarmReport;
use App\Services\FarmReportService;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Facades\Log;

class FarmReportsController extends Controller
{

    public function __construct(
        private FarmReportService $farmReportService
    ) {
        $this->authorizeResource(FarmReport::class, 'farm_report');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm): ResourceCollection
    {
        $reports = $this->farmReportService->getPaginatedReports($farm);

        return FarmReportResource::collection($reports);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFarmReportRequest $request, Farm $farm)
    {
        $validated = $request->validated();
        $reports = $this->farmReportService->createReports($validated, $farm, $request->user());

        // Return the first report as the main response (for backward compatibility)
        return new FarmReportResource($reports[0]);
    }

    /**
     * Display the specified resource.
     */
    public function show(FarmReport $farmReport): FarmReportResource
    {
        $farmReport->load(['operation', 'labour', 'reportable']);
        return new FarmReportResource($farmReport);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFarmReportRequest $request, FarmReport $farmReport): FarmReportResource
    {
        $validated = $request->validated();
        $farm = $farmReport->farm;

        $updatedReports = $this->farmReportService->updateReports($validated, $farm, $request->user(), $farmReport);

        // Return the first updated report as the main response (for backward compatibility)
        $firstReport = $updatedReports[0];
        $firstReport->load(['operation', 'labour', 'reportable']);

        return new FarmReportResource($firstReport->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FarmReport $farmReport)
    {
        $this->farmReportService->deleteReport($farmReport);
        return response()->noContent();
    }

    /**
     * Verify the specified farm report.
     */
    public function verify(FarmReport $farmReport)
    {
        $this->authorize('update', $farmReport);

        $verifiedReport = $this->farmReportService->verifyReport($farmReport);

        return new FarmReportResource($verifiedReport->fresh());
    }

    /**
     * Filter farm reports based on multiple criteria
     */
    public function filter(FarmReportFilterRequest $request, Farm $farm): ResourceCollection
    {
        Log::info('Filter request received', $request->all());
        $filters = $request->validated()['filters'];
        $reports = $this->farmReportService->getFilteredReports($farm, $filters);

        return FarmReportResource::collection($reports);
    }
}
