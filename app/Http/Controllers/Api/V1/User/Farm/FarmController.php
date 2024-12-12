<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
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
     * @param Request $request
     * @return \App\Http\Resources\FarmResource
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:farms,name,NULL,id,user_id,' . $request->user()->id,
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|string|regex:/^(\-?\d+(\.\d+)?),\s*(\-?\d+(\.\d+)?)$/',
            'zoom' => 'required|numeric|min:1',
            'area' => 'required|numeric|min:0',
            'crop_id' => 'required|exists:crops,id',
        ]);

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

        return response()->json([], JsonResponse::HTTP_CREATED);
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
     * @param Request $request
     * @param \App\Models\Farm $farm
     * @return \App\Http\Resources\FarmResource
     */
    public function update(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:farms,name,' . $farm->id . ',id,user_id,' . $request->user()->id,
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|string',
            'center' => 'required|regex:/^(\-?\d+(\.\d+)?),\s*(\-?\d+(\.\d+)?)$/',
            'zoom' => 'required|numeric|min:1',
            'area' => 'required|numeric|min:0',
            'crop_id' => 'required|exists:crops,id',
        ]);

        $farm->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'crop_id' => $request->crop_id
        ]);

        return response()->json([], JsonResponse::HTTP_OK);
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
