<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\VolkOilSprayRequest;
use App\Http\Resources\VolkOilSprayResource;
use App\Models\Farm;
use App\Models\VolkOilSpray;
use Illuminate\Http\Request;

class VolkOilSprayController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $notifications = VolkOilSpray::for($farm)
            ->when(request()->boolean('archived') === true, function ($query) {
                return $query->onlyTrashed();
            })->simplePaginate(10);

        return VolkOilSprayResource::collection($notifications);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(VolkOilSprayRequest $request, Farm $farm)
    {
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
        return new VolkOilSprayResource($volkOilSpray);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(VolkOilSprayRequest $request, VolkOilSpray $volkOilSpray)
    {
        $volkOilSpray->update($request->all());

        return new VolkOilSprayResource($volkOilSpray->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VolkOilSpray $volkOilSpray)
    {
        $volkOilSpray->delete();

        return response()->json([
            'message' => __('Volk oil spray notification deleted successfully.'),
        ]);
    }
}
