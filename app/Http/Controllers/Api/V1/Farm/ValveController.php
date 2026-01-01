<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\ValveResource;
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
        $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|array',
            'is_open' => 'boolean',
            'irrigation_area' => 'required|numeric|min:0',
            'dripper_count' => 'required|integer|min:0',
            'dripper_flow_rate' => 'required|numeric|min:0',
        ]);

        $valve = $plot->valves()->create($request->all());

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
        $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|array',
            'is_open' => 'boolean',
            'irrigation_area' => 'required|numeric|min:0',
            'dripper_count' => 'required|integer|min:0',
            'dripper_flow_rate' => 'required|numeric|min:0',
        ]);

        $valve->update($request->all());

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
