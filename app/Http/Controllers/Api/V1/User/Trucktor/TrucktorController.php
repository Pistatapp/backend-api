<?php

namespace App\Http\Controllers\Api\V1\User\Trucktor;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrucktorResource;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Trucktor;
use Illuminate\Http\Request;

class TrucktorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        return TrucktorResource::collection(
            $farm->trucktors()->with(
                'driver:id,trucktor_id,name,mobile',
                'gpsDevice:id,trucktor_id,imei'
            )->simplePaginate(10)
        );
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

        $this->authorize('create', [Trucktor::class, $farm]);

        $trucktor = $farm->trucktors()->create($request->only([
            'name',
            'start_work_time',
            'end_work_time',
            'expected_daily_work_time',
            'expected_monthly_work_time',
            'expected_yearly_work_time',
        ]));

        return new TrucktorResource($trucktor);
    }

    /**
     * Display the specified resource.
     */
    public function show(Trucktor $trucktor)
    {
        return new TrucktorResource($trucktor);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Trucktor $trucktor)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_work_time' => 'required|date_format:H:i',
            'end_work_time' => 'required|date_format:H:i',
            'expected_daily_work_time' => 'required|integer',
            'expected_monthly_work_time' => 'required|integer',
            'expected_yearly_work_time' => 'required|integer',
        ]);

        $this->authorize('update', $trucktor);

        $trucktor->update($request->only([
            'name',
            'start_work_time',
            'end_work_time',
            'expected_daily_work_time',
            'expected_monthly_work_time',
            'expected_yearly_work_time',
        ]));

        return new TrucktorResource($trucktor);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Trucktor $trucktor)
    {
        $this->authorize('delete', $trucktor);

        $trucktor->delete();

        return response()->noContent();
    }

    /**
     * Get devices of the user which are not assigned to any trucktor.
     */
    public function getAvailableDevices(Request $request, Trucktor $trucktor)
    {
        $gpsDevices = $request->user()->gpsDevices()->whereDoesntHave('trucktor')->get();

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
     * Assign a device to a trucktor.
     */
    public function assignDevice(Request $request, Trucktor $trucktor, GpsDevice $gpsDevice)
    {
        $this->authorize('assignDevice', [$trucktor, $gpsDevice]);

        $gpsDevice->trucktor()->associate($trucktor)->save();

        return response()->noContent();
    }

    /**
     * Unassign a device from a trucktor.
     */
    public function unassignDevice(Request $request, Trucktor $trucktor, GpsDevice $gpsDevice)
    {
        $this->authorize('update', $trucktor);

        $gpsDevice->trucktor()->disassociate()->save();

        return response()->noContent();
    }
}
