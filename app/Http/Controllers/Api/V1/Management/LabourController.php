<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabourRequest;
use App\Http\Requests\UpdateLabourRequest;
use App\Http\Resources\LabourResource;
use App\Models\Farm;
use App\Models\Labour;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class LabourController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Labour::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $labours = $farm->labours()->with('teams')->paginate();

        return LabourResource::collection($labours);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreLabourRequest $request, Farm $farm)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('labours', 'public');
        }

        // Create user account for the labour
        $user = $this->createUserForLabour($data, $request->user());

        // Assign user_id to labour data and exclude attendance fields (stored in User/AttendanceTracking)
        $data['user_id'] = $user->id;
        $labourData = collect($data)->except(['work_type', 'work_days', 'work_hours', 'start_work_time', 'end_work_time', 'hourly_wage', 'overtime_hourly_wage', 'attendence_tracking_enabled', 'imei'])->all();

        $labour = $farm->labours()->create($labourData);

        if ($request->has('team_id')) {
            $labour->teams()->sync($request->team_id);
        }

        return new LabourResource($labour);
    }

    /**
     * Display the specified resource.
     */
    public function show(Labour $labour)
    {
        $labour->load('teams');

        return new LabourResource($labour);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateLabourRequest $request, Labour $labour)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($labour->image && Storage::disk('public')->exists($labour->image)) {
                Storage::disk('public')->delete($labour->image);
            }
            $data['image'] = $request->file('image')->store('labours', 'public');
        }

        // Update user role if work_type changed (work_type from request, used for role assignment)
        if (isset($data['work_type']) && $labour->user) {
            $role = $data['work_type'] === 'administrative' ? 'employee' : 'labour';
            $labour->user->syncRoles($role);
        }

        // Update user mobile and profile if changed
        if ($labour->user) {
            if (isset($data['mobile']) && $data['mobile'] !== $labour->mobile) {
                $labour->user->update(['mobile' => $data['mobile']]);
            }

            if (isset($data['name']) && $data['name'] !== $labour->name && $labour->user->profile) {
                $labour->user->profile->update([
                    'name' => $data['name'],
                ]);
            }
        }

        $labourData = collect($data)->except(['work_type', 'work_days', 'work_hours', 'start_work_time', 'end_work_time', 'hourly_wage', 'overtime_hourly_wage', 'attendence_tracking_enabled', 'imei'])->all();
        $labour->update($labourData);

        if ($request->has('team_id')) {
            $labour->teams()->sync($request->team_id);
        }

        return new LabourResource($labour->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Labour $labour)
    {
        $labour->delete();

        return response()->noContent();
    }

    /**
     * Create a user account for a labour
     *
     * @param array $labourData
     * @param User $creator
     * @return User
     */
    private function createUserForLabour(array $labourData, User $creator): User
    {
        // Check if user with this mobile already exists
        $user = User::where('mobile', $labourData['mobile'])->first();

        if (!$user) {
            // Generate username from mobile (remove country code if exists)
            $mobile = preg_replace('/^\+?98|^0/', '', $labourData['mobile']);
            $baseUsername = 'labour_' . $mobile;
            $username = $baseUsername;
            $counter = 1;

            // Ensure username is unique
            while (User::where('username', $username)->exists()) {
                $username = $baseUsername . '_' . $counter;
                $counter++;
            }

            // Create user
            $user = User::create([
                'mobile' => $labourData['mobile'],
                'username' => $labourData['username'],
                'created_by' => $creator->id,
            ]);

            // Create profile with name if it doesn't exist
            if (!$user->profile) {
                $user->profile()->create([
                    'name' => $labourData['name'],
                ]);
            }
        }

        // Assign role based on work_type (sync roles to ensure only one role)
        $role = $labourData['work_type'] === 'administrative' ? 'employee' : 'labour';
        $user->syncRoles($role);

        return $user;
    }
}
