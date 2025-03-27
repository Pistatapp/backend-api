<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Crop;
use App\Http\Resources\CropResource;
use Illuminate\Http\Request;

class CropController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Crop::class, 'crop');
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return CropResource::collection(Crop::all());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:crops,name',
            'cold_requirement' => 'nullable|integer|min:0',
        ]);

        $crop = Crop::create($request->only('name', 'cold_requirement'));

        return new CropResource($crop);
    }

    /**
     * Display the specified resource.
     */
    public function show(Crop $crop)
    {
        return new CropResource($crop->load('cropTypes'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Crop $crop)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:crops,name,' . $crop->id . ',id',
            'cold_requirement' => 'nullable|integer|min:0',
        ]);

        $crop->update($request->only('name', 'cold_requirement'));

        return new CropResource($crop->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Crop $crop)
    {
        abort_if($crop->farms()->exists(), 403, 'This crop has farms.');

        $crop->delete();

        return response()->noContent();
    }
}
