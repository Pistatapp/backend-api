<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Labour;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        $users = $query->with('farms', 'labour', 'profile')->simplePaginate();

        return UserResource::collection($users);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $creator = $request->user();

        $user = User::create($request->only('mobile') + ['created_by' => $creator->id]);

        $user->assignRole($request->role);

        $user->profile()->create($request->only('name'));

        $user->farms()->attach($request->farm_id, [
            'role' => $request->role,
            'is_owner' => false,
        ]);

        // Create labour record if role is labour
        if ($request->role === 'labour') {
            $this->createLabourForUser($request, $user);
        }

        return new UserResource($user);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return new UserResource($user->load('farms', 'labour', 'profile'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $user->update($request->only('mobile'));

        $user->profile->update($request->only('name'));

        $user->syncRoles($request->role);

        $user->farms()->sync([$request->farm_id => [
            'role' => $request->role,
            'is_owner' => false,
        ]]);

        // Create or update labour record if role is labour
        if ($request->role === 'labour') {
            if ($user->labour) {
                $this->updateLabourForUser($request, $user);
            } else {
                $this->createLabourForUser($request, $user);
            }
        }

        return new UserResource($user->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response()->noContent();
    }

    /**
     * Create a labour record for a user
     *
     * @param StoreUserRequest|UpdateUserRequest $request
     * @param User $user
     * @return Labour
     */
    private function createLabourForUser($request, User $user): Labour
    {
        $labourData = $request->only([
            'name',
            'personnel_number',
            'mobile',
            'work_type',
            'work_days',
            'work_hours',
            'start_work_time',
            'end_work_time',
            'hourly_wage',
            'overtime_hourly_wage',
            'attendence_tracking_enabled',
            'imei',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            $labourData['image'] = $request->file('image')->store('labours', 'public');
        }

        // Set user_id
        $labourData['user_id'] = $user->id;

        // Set farm_id from the request
        $labourData['farm_id'] = $request->farm_id;

        $labour = Labour::create($labourData);

        return $labour;
    }

    /**
     * Update a labour record for a user
     *
     * @param UpdateUserRequest $request
     * @param User $user
     * @return void
     */
    private function updateLabourForUser(UpdateUserRequest $request, User $user): void
    {
        $labour = $user->labour;

        $labourData = $request->only([
            'name',
            'personnel_number',
            'mobile',
            'work_type',
            'work_days',
            'work_hours',
            'start_work_time',
            'end_work_time',
            'hourly_wage',
            'overtime_hourly_wage',
            'attendence_tracking_enabled',
            'imei',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($labour->image && Storage::disk('public')->exists($labour->image)) {
                Storage::disk('public')->delete($labour->image);
            }
            $labourData['image'] = $request->file('image')->store('labours', 'public');
        }

        $labour->update($labourData);
    }
}
