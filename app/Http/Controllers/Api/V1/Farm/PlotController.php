<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlotResource;
use App\Models\Plot;
use App\Models\Field;
use Illuminate\Http\Request;

class PlotController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Field $field)
    {
        return PlotResource::collection($field->plots);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Field $field)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array',
            'area' => 'required|numeric',
        ]);

        $plot = $field->plots()->create($request->only([
            'name',
            'coordinates',
            'area',
        ]));

        return new PlotResource($plot);
    }

    /**
     * Display the specified resource.
     */
    public function show(Plot $plot)
    {
        return new PlotResource($plot->load('attachments'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Plot $plot)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array',
            'area' => 'required|numeric',
        ]);

        $plot->update($request->only([
            'name',
            'coordinates',
            'area',
        ]));

        return new PlotResource($plot->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Plot $plot)
    {
        $plot->delete();

        return response()->noContent();
    }
}
