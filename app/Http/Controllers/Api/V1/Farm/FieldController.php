<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Models\Field;
use App\Http\Requests\StoreFieldRequest;
use App\Http\Requests\UpdateFieldRequest;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Http\Resources\FieldResource;

class FieldController extends Controller
{
    /**
     * Display a listing of the resource.
     * 
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function index(Farm $farm)
    {
        $fileds = $farm->fields()->get();

        return FieldResource::collection($fileds);
    }

    /**
     * Store a newly created resource in storage.
     * 
     * @param \App\Http\Requests\StoreFieldRequest $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function store(StoreFieldRequest $request, Farm $farm)
    {
        $field = $farm->fields()->create([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'products' => implode(',', $request->products)
        ]);

        return new FieldResource($field);
    }

    /**
     * Display the specified resource.
     * 
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function show(Field $field)
    {
        return new FieldResource($field);
    }

    /**
     * Update the specified resource in storage.
     * 
     * @param \App\Http\Requests\UpdateFieldRequest $request
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function update(UpdateFieldRequest $request, Field $field)
    {
        $field->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'products' => implode(',', $request->products),
        ]);

        return new FieldResource($field);
    }

    /**
     * Remove the specified resource from storage.
     * 
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Response
     */
    public function destroy(Field $field)
    {
        $field->delete();

        return response()->noContent();
    }
}
