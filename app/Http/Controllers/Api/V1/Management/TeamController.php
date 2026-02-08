<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Farm;
use App\Models\Team;
use Illuminate\Http\Request;

class TeamController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Team::class, 'team');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $teams = $farm->teams()->withCount('labours')->with('supervisor')->simplePaginate(10);

        return TeamResource::collection($teams);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'supervisor_id' => 'nullable|integer|exists:labours,id',
            'labours' => 'nullable|array',
            'labours.*' => 'integer|exists:labours,id'
        ]);

        $team = $farm->teams()->create($request->only('name', 'supervisor_id'));

        if ($request->has('labours')) {
            $team->labours()->sync($request->labours);
        }

        return new TeamResource($team);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $team)
    {
        return new TeamResource($team->load('labours', 'supervisor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Team $team)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'supervisor_id' => 'nullable|integer|exists:labours,id',
            'labours' => 'nullable|array',
            'labours.*' => 'integer|exists:labours,id'
        ]);

        $team->update($request->only('name', 'supervisor_id'));

        if ($request->has('labours')) {
            $team->labours()->sync($request->labours);
        }

        return new TeamResource($team->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Team $team)
    {
        $team->delete();
        return response()->noContent();
    }
}
