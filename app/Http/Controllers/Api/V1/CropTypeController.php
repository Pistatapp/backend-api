<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCropTypeRequest;
use App\Http\Requests\UpdateCropTypeRequest;
use App\Http\Resources\CropTypeResource;
use App\Models\Crop;
use App\Models\CropType;
use App\Services\SearchService;
use Illuminate\Http\Request;

class CropTypeController extends Controller
{
    protected SearchService $searchService;

    public function __construct(SearchService $searchService)
    {
        $this->authorizeResource(CropType::class, 'crop_type');
        $this->searchService = $searchService;
    }

    /**
     * Display a listing of the resource.
     *
     * Query params:
     * - active (0|1): filter by is_active
     * - search: when set, search by name and return without pagination; otherwise paginate
     */
    public function index(Request $request, Crop $crop)
    {
        $this->authorize('view', $crop);

        $user = $request->user();

        // If search parameter is provided, use SearchService
        if ($request->filled('search')) {
            $filters = ['crop_id' => $crop->id];
            
            // Add active filter if provided
            if ($request->has('active')) {
                $filters['active'] = (bool) $request->query('active');
            }
            
            $results = $this->searchService->search($request->query('search'), $user, 'crop_types', $filters);
            return CropTypeResource::collection($results);
        }

        // Otherwise, use regular pagination
        $query = $user->hasRole('root')
            ? $crop->cropTypes()->global()
            : $crop->cropTypes()->accessibleByUser($user->id)->with('creator');

        $query->when($request->has('active'), function ($q) use ($request) {
            $q->where('is_active', (bool) $request->query('active'));
        });

        $cropTypes = $query->paginate();

        return CropTypeResource::collection($cropTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCropTypeRequest $request, Crop $crop)
    {
        $this->authorize('view', $crop);

        $data = $request->only(['name', 'standard_day_degree', 'load_estimation_data']);

        if ($request->user()->hasRole('admin')) {
            $data['created_by'] = $request->user()->id;
        }

        $cropType = $crop->cropTypes()->create($data);

        return new CropTypeResource($cropType->load('creator'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCropTypeRequest $request, CropType $cropType)
    {
        $cropType->update($request->only(['name', 'standard_day_degree', 'is_active', 'load_estimation_data']));

        return new CropTypeResource($cropType->fresh()->load('creator'));
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
