<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Models\Field;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Http\Resources\FieldResource;
use Illuminate\Http\Request;

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
        return FieldResource::collection($farm->fileds);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string',
            'area' => 'required|numeric|min:0',
            'product_type_id' => 'nullable|exists:product_types,id',
        ]);

        $this->authorize('create', Field::class);

        $field = $farm->fields()->create([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'product_type_id' => $request->product_type_id,
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
        return new FieldResource($field->load('attachments', 'productType'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function update(Request $request, Field $field)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string',
            'area' => 'required|numeric|min:0',
            'product_type_id' => 'nullable|exists:product_types,id',
        ]);

        $this->authorize('update', $field);

        $field->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'product_type_id' => $request->product_type_id,
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
        $this->authorize('delete', $field);

        $field->delete();

        return response()->noContent();
    }
}
