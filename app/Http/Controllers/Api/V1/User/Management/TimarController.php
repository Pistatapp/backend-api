<?php

namespace App\Http\Controllers\Api\V1\User\Management;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimarResource;
use App\Models\Farm;
use App\Models\Timar;
use Illuminate\Http\Request;

class TimarController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Timar::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return TimarResource::collection($farm->timars()->simplePaginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:timars,name',
            'color' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $timar = $farm->timars()->create($request->all());

        return new TimarResource($timar);
    }

    /**
     * Display the specified resource.
     */
    public function show(Timar $timar)
    {
        return new TimarResource($timar);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Timar $timar)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:timars,name,' . $timar->id . ',id',
            'color' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $timar->update($request->all());

        return new TimarResource($timar);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Timar $timar)
    {
        $timar->delete();

        return response()->noContent();
    }
}
