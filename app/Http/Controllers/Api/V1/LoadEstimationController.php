<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CropType;
use App\Models\Farm;
use App\Models\Field;
use App\Models\LoadEstimationTable;
use App\Services\LoadEstimationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoadEstimationController extends Controller
{
    private $loadEstimationService;

    public function __construct(LoadEstimationService $loadEstimationService)
    {
        $this->loadEstimationService = $loadEstimationService;
    }

    /**
     * Display the specified resource.
     */
    public function show(CropType $cropType)
    {
        return response()->json(['data' => $cropType->loadEstimationTable]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CropType $cropType)
    {
        $this->validateRequest($request);

        $this->loadEstimationService->updateTable($cropType, $request->json('rows'));

        return $this->successResponse();
    }

    /**
     * Validate the incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    private function validateRequest(Request $request)
    {
        $request->validate([
            'rows' => 'required|array',
            'rows.*.condition' => 'required|string|in:excellent,good,normal,bad',
            'rows.*.fruit_cluster_weight' => 'required|numeric|min:0',
            'rows.*.average_bud_count' => 'nullable|integer|min:0',
            'rows.*.bud_to_fruit_conversion' => 'required|numeric|min:0',
            'rows.*.estimated_to_actual_yield_ratio' => 'required|numeric|min:0',
            'rows.*.tree_yield_weight_grams' => 'required|integer|min:0',
            'rows.*.tree_weight_kg' => 'nullable|integer|min:0',
            'rows.*.tree_count' => 'nullable|integer|min:0',
            'rows.*.total_garden_yield_kg' => 'nullable|numeric|min:0',
        ]);
    }

    /**
     * Return a success response.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    private function successResponse()
    {
        return response()->json([], JsonResponse::HTTP_OK);
    }

    /**
     * Estimate the yield for a field.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function estimate(Request $request, Farm $farm)
    {
        $this->validateEstimateRequest($request);

        $field = Field::findOrFail($request->field_id);
        $cropType = $field->cropType;
        $loadEstimationTable = LoadEstimationTable::where('crop_type_id', $cropType->id)->firstOrFail();
        $estimatedYields = $this->loadEstimationService->calculateEstimatedYields(
            $request->average_bud_count,
            $request->tree_count,
            $loadEstimationTable->rows
        );

        return response()->json(['data' => $estimatedYields]);
    }

    /**
     * Validate the estimate request.
     *
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    private function validateEstimateRequest(Request $request)
    {
        $request->validate([
            'field_id' => 'required|integer|exists:fields,id',
            'average_bud_count' => 'required|integer|min:0',
            'tree_count' => 'required|integer|min:0',
        ]);
    }
}