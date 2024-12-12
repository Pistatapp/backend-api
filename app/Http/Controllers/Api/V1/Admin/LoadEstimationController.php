<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CropType;
use App\Models\Farm;
use App\Models\Field;
use App\Models\LoadEstimationTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoadEstimationController extends Controller
{
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

        $this->updateLoadEstimationTable($cropType, $request->json('rows'));

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
     * Update the load estimation table.
     *
     * @param \App\Models\CropType $cropType
     * @param array $rows
     * @return void
     */
    private function updateLoadEstimationTable(CropType $cropType, array $rows)
    {
        $cropType->loadEstimationTable()->updateOrCreate(
            ['crop_type_id' => $cropType->id],
            ['rows' => $rows]
        );
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
        $estimatedYields = $this->calculateEstimatedYields($request, $loadEstimationTable->rows);

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

    /**
     * Calculate the estimated yields for all conditions.
     *
     * @param \Illuminate\Http\Request $request
     * @param array $loadEstimationTableRows
     * @return array
     */
    private function calculateEstimatedYields(Request $request, array $loadEstimationTableRows)
    {
        $conditions = ['excellent', 'good', 'normal', 'bad'];
        $estimatedYields = [];

        foreach ($conditions as $condition) {
            foreach ($loadEstimationTableRows as $row) {
                if ($row['condition'] === $condition) {
                    $estimatedYields[$condition] = $this->calculateEstimatedYield(
                        $request->average_bud_count,
                        $request->tree_count,
                        $row
                    );
                    break;
                }
            }
        }

        return $estimatedYields;
    }

    /**
     * Calculate the estimated yield.
     *
     * @param int $averageBudCount
     * @param int $treeCount
     * @param array $conditionRow
     * @return array
     */
    private function calculateEstimatedYield($averageBudCount, $treeCount, $conditionRow)
    {
        $estimatedYieldForSingleTreeGrams = $averageBudCount * $conditionRow['fruit_cluster_weight']
            * $conditionRow['bud_to_fruit_conversion']
            * $conditionRow['estimated_to_actual_yield_ratio'];

        $estimatedYieldForSingleTreeKg = $estimatedYieldForSingleTreeGrams / 1000;
        $estimatedYield = $estimatedYieldForSingleTreeKg * $treeCount;

        return [
            'estimated_yield_per_tree_kg' => (int)$estimatedYieldForSingleTreeKg,
            'estimated_yield_total_kg' => (int)$estimatedYield,
        ];
    }
}
