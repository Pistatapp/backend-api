<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolkOilSprayRequest;
use App\Http\Resources\VolkOilSprayResource;
use App\Models\Farm;
use App\Models\VolkOilSpray;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class VolkOilSprayController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $this->authorize('view', $farm);

        $notifications = VolkOilSpray::for($farm)
            ->when(request()->boolean('archived') === true, function ($query) {
                return $query->onlyTrashed();
            })->paginate(10);

        return VolkOilSprayResource::collection($notifications);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(VolkOilSprayRequest $request, Farm $farm)
    {
        $this->authorize('view', $farm);

        $request->merge([
            'farm_id' => $farm->id,
            'created_by' => $request->user()->id,
        ]);

        $notification = VolkOilSpray::create($request->all());

        return new VolkOilSprayResource($notification);
    }

    /**
     * Display the specified resource.
     */
    public function show(VolkOilSpray $volkOilSpray)
    {
        $this->authorize('view', $volkOilSpray->farm);

        return new VolkOilSprayResource($volkOilSpray);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(VolkOilSprayRequest $request, VolkOilSpray $volkOilSpray)
    {
        $this->authorize('view', $volkOilSpray->farm);

        $volkOilSpray->update($request->all());

        return new VolkOilSprayResource($volkOilSpray->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VolkOilSpray $volkOilSpray)
    {
        $this->authorize('view', $volkOilSpray->farm);

        $volkOilSpray->delete();

        return response()->noContent();
    }
}
