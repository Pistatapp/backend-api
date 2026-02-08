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
     *
     * Query params:
     * - active (0|1): filter by is_active
     * - search: when set, search by name and return without pagination; otherwise paginate
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = $user->hasRole('root')
            ? Crop::global()
            : Crop::accessibleByUser($user->id)->with('creator');

        $query->when($request->has('active'), function ($q) use ($request) {
            $q->where('is_active', (bool) $request->query('active'));
        });

        $query->when($request->filled('search'), function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->query('search') . '%');
        });

        $crops = $request->filled('search')
            ? $query->get()
            : $query->paginate();

        return CropResource::collection($crops);
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

        $data = $request->only('name', 'cold_requirement');

        if ($request->user()->hasRole('admin')) {
            $data['created_by'] = $request->user()->id;
        }

        $crop = Crop::create($data);

        return new CropResource($crop->load('creator'));
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
            'is_active' => 'boolean',
        ]);

        $crop->update($request->only('name', 'cold_requirement', 'is_active'));

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
