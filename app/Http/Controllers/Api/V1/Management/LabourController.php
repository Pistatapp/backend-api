<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabourRequest;
use App\Http\Requests\UpdateLabourRequest;
use App\Http\Resources\LabourResource;
use App\Models\Farm;
use App\Models\Labour;
use Illuminate\Http\Request;

class LabourController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $labours = $farm->labours();

        if (request()->has('search')) {
            $labours = $labours->where('fname', 'like', '%' . request()->search . '%')
                ->orWhere('lname', 'like', '%' . request()->search . '%')
                ->get();
        } else {
            $labours = $labours->simplePaginate();
        }

        return LabourResource::collection($labours);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLabourRequest $request, Farm $farm)
    {
        $labour = $farm->labours()->create($request->validated());

        if ($request->has('team_id')) {
            $labour->teams()->sync($request->team_id);
        }

        return new LabourResource($labour);
    }

    /**
     * Display the specified resource.
     */
    public function show(Labour $labour)
    {
        return new LabourResource($labour->load('teams'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLabourRequest $request, Labour $labour)
    {
        $labour->update($request->validated());

        if ($request->has('team_id')) {
            $labour->teams()->sync($request->team_id);
        }

        return new LabourResource($labour->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Labour $labour)
    {
        $labour->delete();

        return response()->noContent();
    }
}

