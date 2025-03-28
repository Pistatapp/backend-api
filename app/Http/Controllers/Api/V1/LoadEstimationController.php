<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoadEstimation\LoadEstimationTableUpdateRequest;
use App\Http\Requests\LoadEstimation\LoadEstimateRequest;
use App\Models\CropType;
use App\Models\Farm;
use App\Models\Field;
use App\Models\LoadEstimationTable;
use App\Services\LoadEstimationService;
use Illuminate\Http\JsonResponse;

class LoadEstimationController extends Controller
{
    /**
     * @var LoadEstimationService
     */
    private LoadEstimationService $loadEstimationService;

    /**
     * @param LoadEstimationService $loadEstimationService
     */
    public function __construct(LoadEstimationService $loadEstimationService)
    {
        $this->loadEstimationService = $loadEstimationService;
    }

    /**
     * Display the load estimation table for a crop type.
     *
     * @param CropType $cropType
     * @return JsonResponse
     */
    public function show(CropType $cropType): JsonResponse
    {
        return response()->json([
            'data' => $cropType->loadEstimationTable
        ]);
    }

    /**
     * Update the load estimation table for a crop type.
     *
     * @param LoadEstimationTableUpdateRequest $request
     * @param CropType $cropType
     * @return JsonResponse
     */
    public function update(LoadEstimationTableUpdateRequest $request, CropType $cropType): JsonResponse
    {
        $this->loadEstimationService->updateTable($cropType, $request->validated('rows'));

        return response()->json([
            'message' => 'Load estimation table updated successfully'
        ]);
    }

    /**
     * Estimate the yield for a field based on various conditions.
     *
     * @param LoadEstimateRequest $request
     * @param Farm $farm
     * @return JsonResponse
     */
    public function estimate(LoadEstimateRequest $request, Farm $farm): JsonResponse
    {
        $field = Field::findOrFail($request->validated('field_id'));
        $cropType = $field->cropType;

        $loadEstimationTable = LoadEstimationTable::where('crop_type_id', $cropType->id)
            ->firstOrFail();

        $estimatedYields = $this->loadEstimationService->calculateEstimatedYields(
            $request->validated('average_bud_count'),
            $request->validated('tree_count'),
            $loadEstimationTable->rows
        );

        return response()->json([
            'data' => $estimatedYields
        ]);
    }
}
