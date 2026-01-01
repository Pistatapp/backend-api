<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Models\Pump;
use App\Http\Requests\StorePumpRequest;
use App\Http\Requests\UpdatePumpRequest;
use App\Http\Requests\PumpIrrigationReportRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\PumpResource;
use App\Models\Farm;
use App\Models\Irrigation;
use App\Services\PumpIrrigationReportService;

class PumpController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->authorizeResource(Pump::class, 'pump');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return PumpResource::collection($farm->pumps);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePumpRequest $request, Farm $farm)
    {
        $pump = $farm->pumps()->create($request->validated());

        return new PumpResource($pump);
    }

    /**
     * Display the specified resource.
     */
    public function show(Pump $pump)
    {
        return new PumpResource($pump->load('attachments'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePumpRequest $request, Pump $pump)
    {
        $pump->update($request->validated());

        return new PumpResource($pump->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pump $pump)
    {
        $pump->delete();

        return response()->noContent();
    }

    /**
     * Generate irrigation report for a pump.
     */
    public function generateIrrigationReport(PumpIrrigationReportRequest $request, Pump $pump)
    {
        $service = new PumpIrrigationReportService();

        $report = $service->getPumpReports(
            $pump->id,
            $request->start_date,
            $request->end_date
        );

        return response()->json([
            'data' => $report,
        ]);
    }
}
