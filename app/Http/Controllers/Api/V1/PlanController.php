<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\PlanResource;

class PlanController extends Controller
{
    /**
     * Display a listing of the plans.
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index()
    {
        return PlanResource::collection(Plan::all());
    }

    /**
     * Store a newly created plan in storage.
     *
     * @param  \App\Http\Requests\StorePlanRequest  $request
     * @return \App\Http\Resources\PlanResource
     */
    public function store(StorePlanRequest $request)
    {
        $plan = Plan::create($request->validated());
        return new PlanResource($plan);
    }

    /**
     * Display the specified plan.
     *
     * @param  \App\Models\Plan  $plan
     * @return \App\Http\Resources\PlanResource
     */
    public function show(Plan $plan)
    {
        return new PlanResource($plan);
    }

    /**
     * Update the specified plan in storage.
     *
     * @param  \App\Http\Requests\UpdatePlanRequest  $request
     * @param  \App\Models\Plan  $plan
     * @return \App\Http\Resources\PlanResource
     */
    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $plan->update($request->validated());
        return new PlanResource($plan);
    }

    /**
     * Remove the specified plan from storage.
     *
     * @param  \App\Models\Plan  $plan
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Plan $plan)
    {
        $plan->delete();
        return response()->json(['message' => 'Plan deleted successfully']);
    }
}
