<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Farm;
use App\Models\Team;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $teams = $farm->teams()->withCount('labours');

        if (request()->has('search')) {
            $teams = $teams->where('name', 'like', '%' . request()->search . '%')
                ->get();
        } else {
            $teams = $teams->simplePaginate(10);
        }
        return TeamResource::collection($teams);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'supervisor_id' => 'nullable|integer|exists:labour,id'
        ]);

        $team = $farm->teams()->create($request->all());

        return new TeamResource($team);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $team)
    {
        return new TeamResource($team->load('labour', 'supervisor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Team $team)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'supervisor_id' => 'nullable|integer|exists:labour,id'
        ]);

        $team->update($request->only('name', 'supervisor_id'));

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
