<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\FarmReportResource;
use App\Models\Farm;
use App\Models\FarmReport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function store(Request $request, Farm $farm)
    {
        $this->validateRequest($request);

        $reportableModelClass = 'App\Models\\' . ucfirst($request->reportable_type);
        $reportableModel = $reportableModelClass::findOrFail($request->reportable_id);

        $farmReport = $reportableModel->reports()->create([
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
    public function update(Request $request, FarmReport $farmReport)
    {
        $this->validateRequest($request);

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

    /**
     * Validate the request.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    private function validateRequest(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'operation_id' => 'required|integer|exists:operations,id',
            'labour_id' => 'required|integer|exists:labours,id',
            'description' => 'required|string',
            'value' => 'required|numeric',
            'reportable_type' => [
                'nullable',
                'string',
                Rule::in(['tree', 'field', 'row']),
                Rule::requiredIf(fn () => $request->method() === 'POST'),
            ],
            'reportable_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $request->method() === 'POST'),
            ]
        ]);
    }
}
