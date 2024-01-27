<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmRequest;
use App\Http\Requests\UpdateFarmRequest;
use App\Http\Resources\FarmResource;
use App\Models\Farm;
use Illuminate\Http\Request;

class FarmController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Farm::class, 'farm');
    }

    /**
     * Get all farms for the user
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $farms = request()->user()
            ->farms()
            ->withCount('trees')
            ->withCount('fields')
            ->get();

        return FarmResource::collection($farms);
    }

    /**
     * Get a single farm
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Farm $farm)
    {
        return new FarmResource($farm);
    }

    /**
     * Create a new farm
     * @param StoreFarmRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreFarmRequest $request)
    {
        $farm = request()->user()->farms()->create([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'products' => implode(',', $request->products)
        ]);

        return new FarmResource($farm);
    }

    /**
     * Update a farm
     * @param UpdateFarmRequest $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(UpdateFarmRequest $request, Farm $farm)
    {
        $farm->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'products' => implode(',', $request->products)
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
}
