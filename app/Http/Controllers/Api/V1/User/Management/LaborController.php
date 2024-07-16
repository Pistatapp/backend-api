<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLaborRequest;
use App\Http\Requests\UpdateLaborRequest;
use App\Http\Resources\LaborResource;
use App\Models\Farm;
use App\Models\Labor;
use Illuminate\Http\Request;

class LaborController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $labors = $farm->labors();

        if (request()->boolean('without_pagination')) {
            $labors = $labors->get();
        } else {
            $labors = $labors->simplePaginate(10);
        }

        return LaborResource::collection($labors);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLaborRequest $request, Farm $farm)
    {
        $labor = $farm->labors()->create($request->validated());

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
