<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\tractorResource;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\tractor;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TractorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $tractors = $farm->tractors()->with('driver','gpsDevice')->simplePaginate(25);
        return TractorResource::collection($tractors);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Farm $farm)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_work_time' => 'required|date_format:H:i',
            'end_work_time' => 'required|date_format:H:i',
            'expected_daily_work_time' => 'required|integer:0,24',
            'expected_monthly_work_time' => 'required|integer:0,744',
            'expected_yearly_work_time' => 'required|integer:0,8760',
        ]);

        $this->authorize('create', [Tractor::class, $farm]);

        $tractor = $farm->tractors()->create($request->only([
            'name',
            'start_work_time',
            'end_work_time',
            'expected_daily_work_time',
            'expected_monthly_work_time',
            'expected_yearly_work_time',
        ]));

        return new TractorResource($tractor);
    }

    /**
     * Display the specified resource.
     */
    public function show(Tractor $tractor)
    {
        $tractor->load(['driver', 'gpsDevice',
            'gpsDailyReports' => function ($query) {
                $query->latest('date')->limit(7);
            },
        ]);

        return new TractorResource($tractor);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Tractor $tractor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_work_time' => 'required|date_format:H:i',
            'end_work_time' => 'required|date_format:H:i',
            'expected_daily_work_time' => 'required|integer',
            'expected_monthly_work_time' => 'required|integer',
            'expected_yearly_work_time' => 'required|integer',
        ]);

        $this->authorize('update', $tractor);

        $tractor->update($request->only([
            'name',
            'start_work_time',
            'end_work_time',
            'expected_daily_work_time',
            'expected_monthly_work_time',
            'expected_yearly_work_time',
        ]));

        return new TractorResource($tractor->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tractor $tractor)
    {
        $this->authorize('delete', $tractor);

        $tractor->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }

    /**
     * Get devices of the user which are not assigned to any tractor.
     */
    public function getAvailableDevices(Request $request, Tractor $tractor)
    {
        $gpsDevices = $request->user()->gpsDevices()->whereDoesntHave('tractor')->get();

        return response()->json([
            'data' => $gpsDevices->map(function ($device) {
                return [
                    'id' => $device->id,
                    'name' => $device->name,
                    'imei' => $device->imei,
                ];
            }),
        ]);
    }

    /**
     * Assign a device to a tractor.
     */
    public function assignDevice(Request $request, Tractor $tractor, GpsDevice $gpsDevice)
    {
        $this->authorize('assignDevice', [$tractor, $gpsDevice]);

        $gpsDevice->tractor()->associate($tractor)->save();

        return response()->noContent();
    }

    /**
     * Unassign a device from a tractor.
     */
    public function unassignDevice(Request $request, Tractor $tractor, GpsDevice $gpsDevice)
    {
        $this->authorize('update', $tractor);

        $gpsDevice->tractor()->disassociate()->save();

        return response()->noContent();
    }
}
