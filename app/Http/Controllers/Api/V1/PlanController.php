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
    public function index()
    {
        return PlanResource::collection(Plan::all());
    }

    public function store(StorePlanRequest $request)
    {
        $plan = Plan::create($request->validated());
        return new PlanResource($plan);
    }

    public function show(Plan $plan)
    {
        return new PlanResource($plan->load('features'));
    }

    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $plan->update($request->validated());
        return new PlanResource($plan);
    }

    public function destroy(Plan $plan)
    {
        $plan->delete();
        return response()->json(null, 204);
    }
}
