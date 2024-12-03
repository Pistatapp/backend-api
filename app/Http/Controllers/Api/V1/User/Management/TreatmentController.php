<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\TreatmentResource;
use App\Models\Farm;
use App\Models\Treatment;
use Illuminate\Http\Request;

class TreatmentController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Treatment::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return TreatmentResource::collection($farm->treatments);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:treatments,name',
            'color' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $treatment = $farm->treatments()->create($request->all());

        return new TreatmentResource($treatment);
    }

    /**
     * Display the specified resource.
     */
    public function show(Treatment $treatment)
    {
        return new TreatmentResource($treatment);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Treatment $treatment)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:treatments,name,' . $treatment->id . ',id',
            'color' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $treatment->update($request->only('name', 'color', 'description'));

        return new TreatmentResource($treatment);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Treatment $treatment)
    {
        $treatment->delete();

        return response()->noContent();
    }
}
