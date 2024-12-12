<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Models\Field;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Http\Resources\FieldResource;
use App\Http\Resources\ValveResource;
use App\Models\Valve;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class FieldController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Field::class);
    }

    /**
     * Display a listing of the resource.
     *
     * @param \App\Models\Farm $farm
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function index(Farm $farm)
    {
        return FieldResource::collection($farm->fields);
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
            'crop_type_id' => 'nullable|exists:crop_types,id',
        ]);

        $field = $farm->fields()->create([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'crop_type_id' => $request->crop_type_id,
        ]);

        return response()->json([], JsonResponse::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Resources\FieldResource
     */
    public function show(Field $field)
    {
        $fields = $field->load([
            'attachments',
            'cropType',
            'reports.operation',
            'reports.labour',
            'irrigations',
        ])->loadCount('rows', 'blocks');

        return new FieldResource($fields);
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
            'crop_type_id' => 'nullable|exists:crop_types,id',
        ]);

        $field->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'area' => $request->area,
            'crop_type_id' => $request->crop_type_id,
        ]);

        return response()->json([], JsonResponse::HTTP_OK);
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

        return response()->json([], JsonResponse::HTTP_GONE);
    }

    /**
     * Get the valves for the field.
     *
     * @param \App\Models\Field $field
     * @return \Illuminate\Http\Resources\ValveResource
     */
    public function getValvesForField(Field $field)
    {
        $valves = Valve::where('field_id', $field->id)->with('field')->get();

        return ValveResource::collection($valves);
    }
}
