<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLabourRequest;
use App\Http\Requests\UpdateLabourRequest;
use App\Http\Resources\LabourResource;
use App\Models\Farm;
use App\Models\Labour;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LabourController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->authorizeResource(Labour::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Farm $farm)
    {
        $labours = $farm->labours();

        if (request()->has('search')) {
            $labours = $labours->where('name', 'like', '%' . request()->search . '%')
                ->get();
        } else {
            $labours = $labours->with('currentShiftSchedule.shift')->simplePaginate();
        }

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

        // Assign user_id to labour data
        $data['user_id'] = $user->id;

        $labour = $farm->labours()->create($data);

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
        $labour->load('shiftSchedules.shift', 'teams');

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

        // Update user role if work_type changed
        if (isset($data['work_type']) && $data['work_type'] !== $labour->work_type && $labour->user) {
            $role = $data['work_type'] === 'administrative' ? 'employee' : 'labour';
            $labour->user->syncRoles($role);
        }

        // Update user mobile and profile if changed
        if ($labour->user) {
            if (isset($data['mobile']) && $data['mobile'] !== $labour->mobile) {
                $labour->user->update(['mobile' => $data['mobile']]);
            }

            if (isset($data['name']) && $data['name'] !== $labour->name && $labour->user->profile) {
                $nameParts = $this->splitName($data['name']);
                $labour->user->profile->update([
                    'first_name' => $nameParts['first_name'],
                    'last_name' => $nameParts['last_name'],
                ]);
            }
        }

        $labour->update($data);

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
                'username' => $username,
                'created_by' => $creator->id,
            ]);

            // Create profile with name if it doesn't exist
            if (!$user->profile) {
                $nameParts = $this->splitName($labourData['name']);
                $user->profile()->create([
                    'first_name' => $nameParts['first_name'],
                    'last_name' => $nameParts['last_name'],
                ]);
            }
        }

        // Assign role based on work_type (sync roles to ensure only one role)
        $role = $labourData['work_type'] === 'administrative' ? 'employee' : 'labour';
        $user->syncRoles($role);

        return $user;
    }

    /**
     * Split a full name into first name and last name
     *
     * @param string $fullName
     * @return array
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }
}
