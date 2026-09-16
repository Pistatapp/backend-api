<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\CultivarRequiredForLoadEstimation;
use App\Exceptions\CropRequiredForLoadEstimation;
use App\Exceptions\CultivarProductMismatchForLoadEstimation;
use App\Exceptions\UnsupportedCultivarForLoadEstimation;
use App\Http\Controllers\Controller;
use App\Models\CropType;
use App\Models\Farm;
use App\Services\LoadEstimationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoadEstimationController extends Controller
{
    public function __construct(
        private LoadEstimationService $loadEstimationService
    ) {
        $this->middleware('role:root', ['only' => ['update']]);
    }

    /**
     * Display the legacy configuration resource.
     */
    public function show(CropType $cropType)
    {
        $this->authorize('view', $cropType);

        return response()->json(['data' => $cropType->loadEstimationTable]);
    }

    /**
     * Update the legacy table resource. Farm estimation does not consume it.
     */
    public function update(Request $request, CropType $cropType)
    {
        $this->validateRequest($request);

        $this->loadEstimationService->updateTable($cropType, $request->json('rows'));

        return $this->successResponse();
    }

    private function validateRequest(Request $request): void
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

    private function successResponse(): JsonResponse
    {
        return response()->json([], JsonResponse::HTTP_OK);
    }

    /**
     * Estimate a Field selected from the requested Farm.
     *
     * The route keeps field_id for backward compatibility. The field is
     * resolved through the farm relation so a cross-farm identifier cannot be
     * used to calculate another farm's estimate.
     */
    public function estimate(Request $request, Farm $farm): JsonResponse
    {
        $request->validate([
            'field_id' => ['required', 'integer'],
            'average_bud_count' => [
                'required',
                'integer',
                'min:0',
                'max:' . LoadEstimationService::MAX_AVERAGE_BUD_COUNT,
            ],
            'tree_count' => [
                'required',
                'integer',
                'min:0',
                'max:' . LoadEstimationService::MAX_TREE_COUNT,
            ],
            'cluster_weight' => 'required|array|size:4',
            'cluster_weight.excellent' => [
                'required',
                'numeric',
                'min:0',
                'max:' . LoadEstimationService::MAX_CLUSTER_WEIGHT_GRAMS,
            ],
            'cluster_weight.good' => [
                'required',
                'numeric',
                'min:0',
                'max:' . LoadEstimationService::MAX_CLUSTER_WEIGHT_GRAMS,
            ],
            'cluster_weight.normal' => [
                'required',
                'numeric',
                'min:0',
                'max:' . LoadEstimationService::MAX_CLUSTER_WEIGHT_GRAMS,
            ],
            'cluster_weight.bad' => [
                'required',
                'numeric',
                'min:0',
                'max:' . LoadEstimationService::MAX_CLUSTER_WEIGHT_GRAMS,
            ],
        ]);

        abort_unless(
            $farm->users()->whereKey($request->user()->id)->exists(),
            JsonResponse::HTTP_FORBIDDEN
        );

        $field = $farm->fields()
            ->with('cropType')
            ->whereKey($request->integer('field_id'))
            ->first();

        abort_unless($field, JsonResponse::HTTP_NOT_FOUND);
        $farm->loadMissing('crop');
        $field->setRelation('farm', $farm);

        try {
            $estimate = $this->loadEstimationService->estimate(
                $field,
                (int) $request->integer('average_bud_count'),
                (int) $request->integer('tree_count'),
                $request->input('cluster_weight'),
            );
        } catch (CultivarRequiredForLoadEstimation $exception) {
            return $this->cultivarErrorResponse(
                $exception->getMessage(),
                CultivarRequiredForLoadEstimation::ERROR_CODE
            );
        } catch (UnsupportedCultivarForLoadEstimation $exception) {
            return $this->cultivarErrorResponse(
                $exception->getMessage(),
                UnsupportedCultivarForLoadEstimation::ERROR_CODE
            );
        } catch (CropRequiredForLoadEstimation $exception) {
            return $this->structuredEstimateError(
                $exception->getMessage(),
                CropRequiredForLoadEstimation::ERROR_CODE,
                'crop_id'
            );
        } catch (CultivarProductMismatchForLoadEstimation $exception) {
            return $this->structuredEstimateError(
                $exception->getMessage(),
                CultivarProductMismatchForLoadEstimation::ERROR_CODE,
                'crop_type_id'
            );
        }

        return response()->json(['data' => $estimate]);
    }

    private function cultivarErrorResponse(string $message, string $errorCode): JsonResponse
    {
        return $this->structuredEstimateError($message, $errorCode, 'crop_type_id');
    }

    private function structuredEstimateError(string $message, string $errorCode, string $field): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error_code' => $errorCode,
            'field' => $field,
        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }
}
