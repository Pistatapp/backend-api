<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CropTypeResource;
use App\Models\Crop;
use App\Models\CropType;
use Illuminate\Http\Request;

class CropTypeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Crop $crop)
    {
        return CropTypeResource::collection($crop->cropTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Crop $crop)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:crop_types,name',
            'standard_day_degree' => 'nullable|numeric',
        ]);

        $cropType = $crop->cropTypes()->create($request->only('name'));

        return new CropTypeResource($cropType);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CropType $cropType)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:crop_types,name,' . $cropType->id . ',id',
            'standard_day_degree' => 'nullable|numeric',
        ]);

        $cropType->update($request->only('name'));

        return new CropTypeResource($cropType);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CropType $cropType)
    {
        abort_if($cropType->fields()->exists(), 400, 'This crop type has fields.');

        $cropType->delete();

        return response()->noContent();
    }
}
