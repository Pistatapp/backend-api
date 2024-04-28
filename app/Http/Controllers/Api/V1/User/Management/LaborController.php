<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaborRequest;
use App\Http\Requests\UpdateLaborRequest;
use App\Http\Resources\LaborResource;
use App\Models\Labor;
use App\Models\Team;
use Illuminate\Http\Request;

class LaborController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Team $team)
    {
        return LaborResource::collection($team->labors);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLaborRequest $request, Team $team)
    {
        $labor = $team->labors()->create($request->validated());

        return new LaborResource($labor);
    }

    /**
     * Display the specified resource.
     */
    public function show(Labor $labor)
    {
        return new LaborResource($labor->load('team'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLaborRequest $request, Labor $labor)
    {
        $labor->update($request->validated());

        return new LaborResource($labor->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Labor $labor)
    {
        $labor->delete();

        return response()->noContent();
    }
}
