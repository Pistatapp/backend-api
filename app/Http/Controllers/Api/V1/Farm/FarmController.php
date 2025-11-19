<?php

namespace App\Http\Controllers\Api\V1\Farm;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFarmRequest;
use App\Http\Requests\UpdateFarmRequest;
use App\Http\Requests\AttachUserToFarmRequest;
use App\Http\Resources\FarmResource;
use App\Jobs\CreateFarmChatRoomsJob;
use App\Models\Farm;
use Illuminate\Http\Request;
use App\Models\User;

class FarmController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Farm::class);
    }

    /**
     * Get all farms for the user
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $farms = $request->user()->farms()
            ->withCount(['trees', 'fields', 'labours', 'tractors', 'plans'])
            ->get();

        return FarmResource::collection($farms);
    }

    /**
     * Create a new farm
     *
     * @param StoreFarmRequest $request
     * @return \App\Http\Resources\FarmResource
     */
    public function store(StoreFarmRequest $request)
    {
        $farm = Farm::create([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'crop_id' => $request->crop_id,
        ]);

        $request->user()->farms()->attach($farm, [
            'is_owner' => true,
            'role' => $request->user()->getRoleNames()->first(),
        ]);

        // Create chat rooms for the new farm
        CreateFarmChatRoomsJob::dispatch($farm);

        return new FarmResource($farm);
    }

    /**
     * Get a single farm
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Farm $farm)
    {
        $farm = $farm
            ->load(['crop', 'users' => function ($query) {
                $query->wherePivot('is_owner', false);
            }])
            ->loadCount([
                'trees',
                'fields',
                'labours',
                'plans',
                'tractors' => function ($query) {
                    $query->active();
                }
            ]);

        return new FarmResource($farm);
    }

    /**
     * Update a farm
     *
     * @param UpdateFarmRequest $request
     * @param \App\Models\Farm $farm
     * @return \App\Http\Resources\FarmResource
     */
    public function update(UpdateFarmRequest $request, Farm $farm)
    {
        $farm->update([
            'name' => $request->name,
            'coordinates' => $request->coordinates,
            'center' => $request->center,
            'zoom' => $request->zoom,
            'area' => $request->area,
            'crop_id' => $request->crop_id
        ]);

        return new FarmResource($farm->fresh());
    }

    /**
     * Delete a farm
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Farm $farm)
    {
        $farm->delete();

        return response()->noContent();
    }

    /**
     * Set working environment for the farm
     *
     * @param Request $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function setWorkingEnvironment(Request $request, Farm $farm)
    {
        $this->authorize('setWorkingEnvironment', $farm);

        $request->user()->update([
            'preferences->working_environment' => $farm->id,
        ]);

        return new FarmResource($farm);
    }

    /**
     * Attach a user to a farm
     *
     * @param AttachUserToFarmRequest $request
     * @param Farm $farm
     * @return \Illuminate\Http\JsonResponse
     */
    public function attachUserToFarm(AttachUserToFarmRequest $request, Farm $farm)
    {
        $user = User::find($request->input('user_id'));
        $this->authorize('attach', $user);

        $role = $request->input('role');
        $permissions = $request->getValidatedPermissions();

        $farm->users()->attach($user->id, [
            'role' => $role,
            'is_owner' => false,
        ]);

        // Assign role to user
        $user->assignRole($role);

        // If custom-role, assign specific permissions
        if ($role === 'custom-role' && !empty($permissions)) {
            $user->givePermissionTo($permissions);
        }

        return response()->json(['message' => __('User attached to farm successfully.')]);
    }

    /**
     * Detach a user from a farm
     *
     * @param Farm $farm
     * @param User $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function detachUserFromFarm(Request $request, Farm $farm)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::find($request->input('user_id'));
        $this->authorize('detach', $user);

        $farm->users()->detach($user->id);

        return response()->json(['message' => __('User detached from farm successfully.')]);
    }
}
