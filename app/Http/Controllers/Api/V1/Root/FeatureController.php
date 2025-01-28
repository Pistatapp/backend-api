<?php

namespace App\Http\Controllers\Api\V1\Root;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Http\Request;
use App\Http\Requests\StoreFeatureRequest;
use App\Http\Requests\UpdateFeatureRequest;
use App\Http\Resources\FeatureResource;

class FeatureController extends Controller
{
    /**
     * Return features for the given plan.
     *
     * @param Plan $plan
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Plan $plan): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return FeatureResource::collection($plan->features);
    }

    /**
     * Create and return a new feature.
     *
     * @param StoreFeatureRequest $request
     * @param Plan $plan
     * @return FeatureResource
     */
    public function store(StoreFeatureRequest $request, Plan $plan): FeatureResource
    {
        $feature = $plan->features()->create($request->validated());
        return new FeatureResource($feature);
    }

    /**
     * Return a single feature.
     *
     * @param Feature $feature
     * @return FeatureResource
     */
    public function show(Feature $feature): FeatureResource
    {
        return new FeatureResource($feature);
    }

    /**
     * Update the specified feature.
     *
     * @param UpdateFeatureRequest $request
     * @param Feature $feature
     * @return FeatureResource
     */
    public function update(UpdateFeatureRequest $request, Feature $feature): FeatureResource
    {
        $feature->update($request->validated());
        return new FeatureResource($feature);
    }

    /**
     * Delete the specified feature.
     *
     * @param Feature $feature
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Feature $feature): \Illuminate\Http\JsonResponse
    {
        $feature->delete();
        return response()->json(null, 204);
    }
}
