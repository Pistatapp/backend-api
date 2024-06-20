<?php

namespace App\Http\Controllers\Api\V1\User\Farm;

use App\Http\Controllers\Controller;
use App\Models\ColdRequirementNotification;
use App\Http\Resources\ColdRequirementNotificationResource;
use App\Models\Farm;
use Illuminate\Http\Request;

class ColdRequirementNotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $notifications = ColdRequirementNotification::where('farm_id', $farm->id)->simplePaginate(10);

        return ColdRequirementNotificationResource::collection($notifications);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'min_temp' => 'required|numeric',
            'max_temp' => 'required|numeric',
            'cold_requirement' => 'required|numeric',
            'method' => 'required|string|in:method1,method2',
            'note' => 'nullable|string',
        ]);

        $request->merge([
            'farm_id' => $farm->id,
        ]);

        $coldRequirementNotification = ColdRequirementNotification::create($request->all());

        return new ColdRequirementNotificationResource($coldRequirementNotification);
    }

    /**
     * Display the specified resource.
     */
    public function show(ColdRequirementNotification $coldRequirementNotification)
    {
        return new ColdRequirementNotificationResource($coldRequirementNotification);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ColdRequirementNotification $coldRequirementNotification)
    {
        $request->validate([
            'start_dt' => 'required|date',
            'end_dt' => 'required|date',
            'min_temp' => 'required|numeric',
            'max_temp' => 'required|numeric',
            'cold_requirement' => 'required|numeric',
            'method' => 'required|string|in:method1,method2',
            'note' => 'nullable|string|max:500',
        ]);

        $coldRequirementNotification->update($request->only([
            'start_dt',
            'end_dt',
            'min_temp',
            'max_temp',
            'cold_requirement',
            'method',
            'note',
        ]));

        return new ColdRequirementNotificationResource($coldRequirementNotification->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ColdRequirementNotification $coldRequirementNotification)
    {
        $coldRequirementNotification->delete();

        return response()->json([
            'message' => __('Cold requirement notification deleted successfully.'),
        ]);
    }
}
