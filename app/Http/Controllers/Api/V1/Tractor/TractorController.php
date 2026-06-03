<?php

namespace App\Http\Controllers\Api\V1\Tractor;

use App\Http\Controllers\Controller;
use App\Http\Resources\TractorResource;
use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Maintenance;
use App\Models\MaintenanceReport;
use App\Http\Resources\MaintenanceReportResource;
use App\Models\Tractor;
use App\Models\Driver;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TractorController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Tractor::class, 'tractor');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $tractors = $farm->tractors()->with('driver', 'gpsDevice', 'farm')->simplePaginate(25);
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
        $tractor->load([
            'driver',
            'gpsDevice',
            'farm',
            'gpsMetricsCalculations' => function ($query) {
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
        $tractor->delete();

        // if user wants to delete tractor, disaccosiate driver and gps device from tractor
        $tractor->driver()->update(['tractor_id' => null]);
        $tractor->gpsDevice()->update(['tractor_id' => null]);

        return response()->noContent();
    }

    /**
     * Get devices of the user which are not assigned to any tractor.
     */
    public function getAvailableDevices(Request $request)
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
     * Get the available tractors to assign a device to.
     */
    public function getAvailableTractors(Request $request, Farm $farm)
    {
        $tractors = Tractor::where('farm_id', $farm->id)
            ->whereDoesntHave('gpsDevice')
            ->with(['driver', 'gpsDevice', 'farm'])
            ->get();

        return TractorResource::collection($tractors);
    }

    /**
     * Assign a driver and GPS device to a tractor.
     * This will replace any existing assignments.
     *
     * @param Request $request
     * @param Tractor $tractor
     * @return \Illuminate\Http\JsonResponse
     */
    public function assignments(Request $request, Tractor $tractor)
    {
        $request->validate([
            'driver_id' => 'required|exists:drivers,id',
            'gps_device_id' => 'required|exists:gps_devices,id',
        ]);

        // Load the farm relationship to avoid lazy loading issues
        $tractor->load('farm');

        // Get the new driver and GPS device
        $driver = Driver::findOrFail($request->driver_id);
        $device = GpsDevice::findOrFail($request->gps_device_id);

        // Authorize the assignments
        $this->authorize('makeAssignments', [$tractor, $device, $driver]);

        // Remove existing assignments first
        if ($tractor->driver) {
            $tractor->driver->update(['tractor_id' => null]);
        }
        if ($tractor->gpsDevice) {
            $tractor->gpsDevice->update(['tractor_id' => null]);
        }

        // Make new assignments
        $driver->update(['tractor_id' => $tractor->id]);
        $device->update(['tractor_id' => $tractor->id]);

        return response()->noContent();
    }

    public function enterRepairShop(Request $request, Tractor $tractor)
    {
        $request->validate([
            'maintained_by' => 'nullable|exists:labours,id',
            'description' => 'required|string|max:5000',
        ]);

        $this->authorize('enterRepairShop', $tractor);

        if ($tractor->is_in_repair_shop) {
            return response()->json([
                'message' => __('This tractor is already in the repair shop.'),
            ], 422);
        }

        $maintainedBy = $request->input('maintained_by');
        if ($maintainedBy === null) {
            $maintainedBy = $tractor->farm->labours()->value('id');
        }

        if (!$maintainedBy) {
            return response()->json([
                'message' => __('No labour found to assign this repair report.'),
            ], 422);
        }

        $maintenance = Maintenance::firstOrCreate([
            'farm_id' => $tractor->farm_id,
            'name' => __('Repair Shop'),
        ]);

        $tractor->update(['is_in_repair_shop' => true]);

        $report = MaintenanceReport::create([
            'maintenance_id' => $maintenance->id,
            'maintainable_type' => Tractor::class,
            'maintainable_id' => $tractor->id,
            'created_by' => $request->user()->id,
            'maintained_by' => (int) $maintainedBy,
            'date' => today(),
            'description' => $request->description,
            'repair_shop_entered_at' => Carbon::now(),
            'repair_shop_exited_at' => null,
        ]);

        return response()->json([
            'message' => __('Tractor :name entered repair shop at :datetime', [
                'name' => $tractor->name,
                'datetime' => jdate(now())->format('Y/m/d H:i:s'),
            ]),
            'data' => [
                'tractor' => new TractorResource($tractor->fresh()),
                'maintenance_report' => new MaintenanceReportResource($report),
            ],
        ]);
    }

    public function exitRepairShop(Request $request, Tractor $tractor)
    {
        $this->authorize('exitRepairShop', $tractor);

        if (!$tractor->is_in_repair_shop) {
            return response()->json([
                'message' => __('This tractor is not in the repair shop.'),
            ], 422);
        }

        $openReport = $tractor->maintenanceReports()
            ->whereNotNull('repair_shop_entered_at')
            ->whereNull('repair_shop_exited_at')
            ->latest('repair_shop_entered_at')
            ->first();

        if ($openReport) {
            $openReport->update([
                'repair_shop_exited_at' => Carbon::now(),
            ]);
        }

        $tractor->update(['is_in_repair_shop' => false]);

        return response()->json([
            'message' => __('Tractor :name exited repair shop at :datetime', [
                'name' => $tractor->name,
                'datetime' => jdate(now())->format('Y/m/d H:i:s'),
            ]),
            'data' => [
                'tractor' => new TractorResource($tractor->fresh()),
                'maintenance_report' => new MaintenanceReportResource($openReport),
            ],
        ]);
    }

    public function resetServiceInterval(Request $request, Tractor $tractor)
    {
        $this->authorize('resetServiceInterval', $tractor);

        $tractor->update([
            'last_service_at' => now(),
            'last_service_notified_at' => null,
        ]);

        return response()->json([
            'message' => __('Tractor :name service interval has been reset.', [
                'name' => $tractor->name,
            ]),
            'data' => new TractorResource($tractor->fresh()),
        ]);
    }
}
