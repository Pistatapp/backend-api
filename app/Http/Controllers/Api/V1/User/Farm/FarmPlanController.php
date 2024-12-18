<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmPlanRequest;
use App\Http\Requests\UpdateFarmPlanRequest;
use App\Http\Resources\FarmPlanResource;
use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\FarmPlanDetail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FarmPlanController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(FarmPlan::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $plans = FarmPlan::where('farm_id', $farm->id)
            ->when(request('status'), function ($query) {
                return $query->where('status', request('status'));
            })
            ->with('creator:id,username')
            ->latest()->simplePaginate(10);
        return FarmPlanResource::collection($plans);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFarmPlanRequest $request, Farm $farm)
    {
        $plan = FarmPlan::create([
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
            'created_by' => $request->user()->id,
            'status' => 'pending'
        ]);

        $plan_details_data = [];

        foreach ($request->details as $detail) {
            foreach ($detail['treatables'] as $treatable) {
                $plan_details_data[] = [
                    'farm_plan_id' => $plan->id,
                    'treatment_id' => $detail['treatment_id'],
                    'treatable_id' => $treatable['treatable_id'],
                    'treatable_type' => 'App\Models\\' . ucfirst($treatable['treatable_type']),
                ];
            }
        }

        FarmPlanDetail::insert($plan_details_data);

        return new FarmPlanResource($plan);
    }

    /**
     * Display the specified resource.
     */
    public function show(FarmPlan $farmPlan)
    {
        $farmPlan = $farmPlan->load('details.treatment', 'details.treatable');
        return new FarmPlanResource($farmPlan);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateFarmPlanRequest $request, FarmPlan $farmPlan)
    {
        $farmPlan->update($request->only([
            'name',
            'goal',
            'referrer',
            'counselors',
            'executors',
            'statistical_counselors',
            'implementation_location',
            'used_materials',
            'evaluation_criteria',
            'description',
            'start_date',
            'end_date'
        ]));

        $farmPlan->details()->delete();

        $plan_details_data = [];

        foreach ($request->details as $detail) {
            foreach ($detail['treatables'] as $treatable) {
                $plan_details_data[] = [
                    'farm_plan_id' => $farmPlan->id,
                    'treatment_id' => $detail['treatment_id'],
                    'treatable_id' => $treatable['treatable_id'],
                    'treatable_type' => 'App\Models\\' . ucfirst($treatable['treatable_type']),
                ];
            }
        }

        FarmPlanDetail::insert($plan_details_data);

        return new FarmPlanResource($farmPlan->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FarmPlan $farmPlan)
    {
        $farmPlan->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
