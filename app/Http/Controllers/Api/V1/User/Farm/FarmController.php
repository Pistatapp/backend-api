<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmRequest;
use App\Http\Requests\UpdateFarmRequest;
use App\Http\Resources\FarmResource;
use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

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
        $farms = Farm::where('user_id', Auth::id())
            ->withCount(['trees', 'fields', 'labours', 'trucktors', 'plans'])
            ->get();

        return FarmResource::collection($farms);
    }

    /**
     * Create a new farm
     *
     * @param StoreFarmRequest $request
     * @return \App\Http\Resources\FarmResource
     */
    public function store(StoreFarmRequest $request)
    {
        $farm = Farm::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'crop_id' => $request->crop_id,
            'is_working_environment' => Farm::where('user_id', $request->user()->id)->count() === 0,
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
        $farm = $farm->loadCount(['trees', 'fields', 'labours', 'trucktors', 'plans'])->load('crop');

        return new FarmResource($farm);
    }

    /**
     * Update a farm
     *
     * @param UpdateFarmRequest $request
     * @param \App\Models\Farm $farm
     * @return \App\Http\Resources\FarmResource
     */
    public function update(UpdateFarmRequest $request, Farm $farm)
    {
        $farm->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'crop_id' => $request->crop_id
        ]);

        return new FarmResource($farm->fresh());
    }

    /**
     * Delete a farm
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Farm $farm)
    {
        $farm->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }

    /**
     * Set working environment for the farm
     *
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function setWorkingEnvironment(Farm $farm)
    {
        $farm->changeWorkingEnvironment($farm);

        return new FarmResource($farm);
    }
}
