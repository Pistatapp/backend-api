<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\ValveResource;
use App\Models\Farm;
use App\Models\Plot;
use App\Models\Valve;
use Illuminate\Http\Request;

class ValveController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Valve::class, 'valve');
    }

    /**
     * Display all valves belonging to a farm.
     *
     * This endpoint is used by the irrigation map and selection flows. The
     * resource route is plot-scoped, so it cannot serve the farm-wide mobile
     * request without this explicit read-only projection.
     */
    public function indexForFarm(Request $request, Farm $farm)
    {
        abort_unless(
            $farm->users()->whereKey($request->user()->id)->exists(),
            403,
        );

        $valves = Valve::query()
            ->whereHas('plot.field', function ($query) use ($farm) {
                $query->whereKey($farm->id);
            })
            ->with('plot')
            ->orderBy('id')
            ->get();

        return ValveResource::collection($valves);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Plot $plot)
    {
        return ValveResource::collection($plot->valves);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Plot $plot)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|array',
            'is_open' => 'boolean',
            'irrigation_area' => 'required|numeric|min:0',
            'dripper_count' => 'required|integer|min:0',
            'dripper_flow_rate' => 'required|numeric|min:0',
        ]);

        $valve = $plot->valves()->create($validated);

        return new ValveResource($valve);
    }

    /**
     * Display the specified resource.
     */
    public function show(Valve $valve)
    {
        return new ValveResource($valve);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Valve $valve)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|array',
            'is_open' => 'boolean',
            'irrigation_area' => 'required|numeric|min:0',
            'dripper_count' => 'required|integer|min:0',
            'dripper_flow_rate' => 'required|numeric|min:0',
        ]);

        $valve->update($validated);

        return new ValveResource($valve->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Valve $valve)
    {
        $valve->delete();

        return response()->noContent();
    }
}
