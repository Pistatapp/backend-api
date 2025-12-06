<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterFarmPlanRequest;
use App\Http\Requests\StoreFarmPlanRequest;
use App\Http\Requests\UpdateFarmPlanRequest;
use App\Http\Resources\FarmPlanResource;
use App\Http\Resources\FilteredFarmPlanResource;
use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\FarmPlanDetail;
use Illuminate\Http\Request;

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
     * Filter farm plans based on date range and treatables.
     */
    public function filter(FilterFarmPlanRequest $request, Farm $farm)
    {
        $query = FarmPlan::where('farm_id', $farm->id)
            ->with(['details.treatable']);

        // Filter by date range
        $query->where(function($query) use ($request) {
            $query->where(function($subQuery) use ($request) {
                // Plan overlaps with the requested date range
                $subQuery->where('start_date', '<=', $request->to_date)
                    ->where('end_date', '>=', $request->from_date);
            });
        });

        // Filter by treatables
        if ($request->has('treatable') && is_array($request->treatable)) {
            $query->whereHas('details', function ($q) use ($request) {
                $q->where(function ($subQuery) use ($request) {
                    foreach ($request->treatable as $treatable) {
                        $subQuery->orWhere(function ($treatableQuery) use ($treatable) {
                            $treatableQuery->where('treatable_id', $treatable['treatable_id'])
                                ->where('treatable_type', 'App\Models\\' . ucfirst($treatable['treatable_type']));
                        });
                    }
                });
            });
        }

        $plans = $query->get();

        return FilteredFarmPlanResource::collection($plans);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreFarmPlanRequest $request, Farm $farm)
    {
        $plan = $farm->plans()->create(array_merge(
            $request->only([
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
            ]),
            [
                'created_by' => $request->user()->id,
                'status' => 'pending'
            ]
        ));

        $plan_details_data = collect($request->details)->flatMap(function ($detail) use ($plan) {
            return collect($detail['treatables'])->map(function ($treatable) use ($detail, $plan) {
                return [
                    'farm_plan_id' => $plan->id,
                    'treatment_id' => $detail['treatment_id'],
                    'treatable_id' => $treatable['treatable_id'],
                    'treatable_type' => 'App\Models\\' . ucfirst($treatable['treatable_type']),
                ];
            });
        })->toArray();

        FarmPlanDetail::insert($plan_details_data);

        $plan->load('creator:id,username', 'details.treatment', 'details.treatable');

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

        $farmPlan = $farmPlan->fresh()->load('creator:id,username', 'details.treatment', 'details.treatable');

        return new FarmPlanResource($farmPlan);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FarmPlan $farmPlan)
    {
        $farmPlan->delete();

        return response()->noContent();
    }
}
