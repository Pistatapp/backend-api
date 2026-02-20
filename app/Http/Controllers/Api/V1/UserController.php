<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AttendanceTracking;
use App\Models\GpsDevice;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = User::query();

        if (!$user->hasRole('root')) {
            $workingEnvironment = $user->workingEnvironment();
            $workingEnvironmentId = $workingEnvironment?->id;
            $request->merge(['_working_environment_id' => $workingEnvironmentId]);

            $query->whereHas('farms', function ($query) use ($workingEnvironmentId) {
                $query->where('farms.id', $workingEnvironmentId);
            });
        }

        $query->where('id', '!=', $user->id);

        $users = $query->with('farms', 'profile')->simplePaginate();

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $user = User::create([
            'mobile' => $request->mobile,
            'created_by' => $request->user()->id,
            'username' => $request->role === 'labour' ? 'labour_' . $request->mobile : null,
        ]);

        $user->assignRole($request->role);

        $profile = $user->profile()->create($request->only('name'));

        if ($request->hasFile('image')) {
            $profile->clearMediaCollection('images');
            $profile->addMediaFromRequest('image')->toMediaCollection('images');
        }

        $user->farms()->attach($request->farm_id, [
            'role' => $request->role,
            'is_owner' => false,
        ]);

        // Create or update attendance tracking and tracking device if enabled
        if ($request->boolean('attendance_tracking_enabled')) {
            $this->createOrUpdateAttendanceTracking($request, $user);
            $this->createOrUpdateTrackingDevice($request, $user);
        }

        return new UserResource($user);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return new UserResource($user->load('farms', 'profile'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $user->update($request->only('mobile'));

        $user->profile->update($request->only('name'));

        if ($request->hasFile('image')) {
            $user->profile->clearMediaCollection('images');
            $user->profile->addMediaFromRequest('image')->toMediaCollection('images');
        }

        $user->syncRoles($request->role);

        $user->farms()->sync([$request->farm_id => [
            'role' => $request->role,
            'is_owner' => false,
        ]]);

        if ($request->boolean('attendance_tracking_enabled')) {
            $this->createOrUpdateAttendanceTracking($request, $user);
            $this->createOrUpdateTrackingDevice($request, $user);
        } elseif ($user->attendanceTracking) {
            $user->attendanceTracking->update(['enabled' => false]);
        }

        return new UserResource($user->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->attendanceTracking()->delete();
        $user->profile()->delete();
        $user->farms()->detach();
        $user->delete();
        return response()->noContent();
    }

    /**
     * Create or update attendance tracking record for a user
     *
     * @param StoreUserRequest|UpdateUserRequest $request
     * @param User $user
     * @return AttendanceTracking
     */
    private function createOrUpdateAttendanceTracking($request, User $user): AttendanceTracking
    {
        $attendanceData = $request->only([
            'work_type',
            'work_days',
            'work_hours',
            'start_work_time',
            'end_work_time',
            'hourly_wage',
            'overtime_hourly_wage',
        ]);

        // Set user_id, farm_id and enabled
        $attendanceData['user_id'] = $user->id;
        $attendanceData['farm_id'] = $request->farm_id;
        $attendanceData['enabled'] = true;

        // Clear administrative-specific fields when work_type is shift_based
        if ($attendanceData['work_type'] === 'shift_based') {
            $attendanceData['work_days'] = null;
            $attendanceData['work_hours'] = null;
            $attendanceData['start_work_time'] = null;
            $attendanceData['end_work_time'] = null;
        }

        // Use updateOrCreate to handle both create and update scenarios
        $attendanceTracking = AttendanceTracking::updateOrCreate(
            ['user_id' => $user->id],
            $attendanceData
        );

        return $attendanceTracking;
    }

    /**
     * Create or update the user's worker GPS device when attendance tracking is enabled.
     *
     * @param StoreUserRequest|UpdateUserRequest $request
     * @param User $user
     * @return GpsDevice
     */
    private function createOrUpdateTrackingDevice($request, User $user): GpsDevice
    {
        $tracking = $request->validated('tracking_device');
        $type = $tracking['type'];
        $name = $type === 'mobile_phone'
            ? 'Mobile Phone - ' . $user->mobile
            : 'Personal GPS - ' . $user->mobile;

        $deviceData = [
            'user_id' => $user->id,
            'device_type' => $type,
            'name' => $name,
            'imei' => $tracking['imei'],
            'sim_number' => $tracking['sim_number'],
            'device_fingerprint' => $tracking['device_fingerprint'] ?? null,
        ];

        $device = $user->gpsDevices()->workerDevices()->first();

        if ($device) {
            $device->update($deviceData);
            return $device->fresh();
        }

        return $user->gpsDevices()->create($deviceData);
    }
}
