<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\ValveResource;
use App\Models\Pump;
use App\Models\Valve;
use Illuminate\Http\Request;

class ValveController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Pump $pump)
    {
        return ValveResource::collection($pump->valves);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Pump $pump)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'location' => 'required|string',
            'flow_rate' => 'required|integer|min:0|max:100',
        ]);

        $valve = $pump->valves()->create($request->all());

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
            'location' => 'required|string',
            'flow_rate' => 'required|integer|min:0|max:100',
        ]);

        $valve->update($request->all());

        return new ValveResource($valve);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Valve $valve)
    {
        $valve->delete();

        return response()->noContent();
    }

    /**
     * Toggle the specified resource in storage.
     */
    public function toggle(Valve $valve)
    {
        $valve->update([
            'is_open' => !$valve->is_open,
        ]);

        return new ValveResource($valve);
    }
}
