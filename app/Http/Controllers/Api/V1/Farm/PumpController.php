<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Models\Pump;
use App\Http\Requests\StorePumpRequest;
use App\Http\Requests\UpdatePumpRequest;
use App\Http\Controllers\Controller;
use App\Http\Resources\PumpResource;
use App\Models\Farm;

class PumpController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return PumpResource::collection($farm->pumps);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePumpRequest $request, Farm $farm)
    {
        $pump = $farm->pumps()->create($request->validated());

        return new PumpResource($pump);
    }

    /**
     * Display the specified resource.
     */
    public function show(Pump $pump)
    {
        return new PumpResource($pump->load('attachments'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePumpRequest $request, Pump $pump)
    {
        $pump->update($request->validated());

        return new PumpResource($pump);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Pump $pump)
    {
        $pump->delete();

        return response()->noContent();
    }
}
