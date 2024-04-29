<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Resources\FarmResource;
use App\Models\Farm;
use Illuminate\Http\Request;

class FarmController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Farm::class);
    }

    /**
     * Get all farms for the user
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $farms = request()->user()->farms;

        return FarmResource::collection($farms);
    }

    /**
     * Create a new farm
     *
     * @param Request $request
     * @return \App\Http\Resources\FarmResource
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:farms,name',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string',
            'zoom' => 'required|numeric|min:1',
            'area' => 'required|numeric|min:0',
            'product_id' => 'required|exists:products,id',
        ]);

        $farm = Farm::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'product_id' => $request->product_id,
            'is_working_environment' => Farm::count() === 0,
        ]);

        return new FarmResource($farm);
    }

    /**
     * Get a single farm
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Farm $farm)
    {
        return new FarmResource($farm->loadCount(['trees', 'fields'])->load('product'));
    }


    /**
     * Update a farm
     *
     * @param Request $request
     * @param \App\Models\Farm $farm
     * @return \App\Http\Resources\FarmResource
     */
    public function update(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:farms,name,' . $farm->id,
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string',
            'zoom' => 'required|numeric|min:1',
            'area' => 'required|numeric|min:0',
            'product_id' => 'required|exists:products,id',
        ]);

        $farm->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'product_id' => $request->product_id
        ]);

        return new FarmResource($farm);
    }

    /**
     * Delete a farm
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Farm $farm)
    {
        $farm->delete();

        return response()->noContent();
    }

    /**
     * Set working environment for the farm
     *
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function setWorkingEnvironment(Farm $farm)
    {
        $this->authorize('setWorkingEnvironment', $farm);

        $farm->update([
            'is_working_environment' => true
        ]);

        // Set other farms to false
        Farm::where('id', '!=', $farm->id)
            ->update(['is_working_environment' => false]);

        return new FarmResource($farm);
    }
}
