<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Farm;
use App\Models\Plan;
use App\Models\Feature;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Plan::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $plans = $farm->plans()->with('creator:id,username')->get();
        return PlanResource::collection($plans);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePlanRequest $request, Farm $farm)
    {
        $plan = Plan::create([
            'farm_id' => $farm->id,
            'name' => $request->name,
            'goal' => $request->goal,
            'referrer' => $request->referrer,
            'counselors' => $request->counselors,
            'executors' => $request->executors,
            'statistical_counselors' => $request->statistical_counselors,
            'implementation_location' => $request->implementation_location,
            'used_materials' => $request->used_materials,
            'evaluation_criteria' => $request->evaluation_criteria,
            'description' => $request->description,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'created_by' => auth()->id(),
            'status' => 'pending'
        ]);

        foreach ($request->features as $feature) {
            foreach ($feature['timarables'] as $timarable) {
                $timarable_type = 'App\Models\\' . ucfirst($timarable['timarable_type']);
                Feature::create([
                    'plan_id' => $plan->id,
                    'timar_id' => $feature['timar_id'],
                    'timarable_id' => $timarable['timarable_id'],
                    'timarable_type' => $timarable_type,
                ]);
            }
        }

        return new PlanResource($plan);
    }

    /**
     * Display the specified resource.
     */
    public function show(Plan $plan)
    {
        $plan = $plan->load('features.timar', 'features.timarable');
        return new PlanResource($plan);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $plan->update([
            'name' => $request->name,
            'goal' => $request->goal,
            'referrer' => $request->referrer,
            'counselors' => $request->counselors,
            'executors' => $request->executors,
            'statistical_counselors' => $request->statistical_counselors,
            'implementation_location' => $request->implementation_location,
            'used_materials' => $request->used_materials,
            'evaluation_criteria' => $request->evaluation_criteria,
            'description' => $request->description,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
        ]);

        $plan->features()->delete();

        foreach ($request->features as $feature) {
            foreach ($feature['timarables'] as $timarable) {
                $timarable_type = 'App\Models\\' . ucfirst($timarable['timarable_type']);
                Feature::create([
                    'plan_id' => $plan->id,
                    'timar_id' => $feature['timar_id'],
                    'timarable_id' => $timarable['timarable_id'],
                    'timarable_type' => $timarable_type,
                ]);
            }
        }

        return new PlanResource($plan);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Plan $plan)
    {
        $plan->delete();

        return response()->noContent();
    }
}
