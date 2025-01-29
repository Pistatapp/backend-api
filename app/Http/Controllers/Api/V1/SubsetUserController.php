<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubSetUserRequest;
use App\Http\Requests\UpdateSubSetUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class SubsetUserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class);
    }

    /**
     * Display a list of subset users.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function index(Request $request)
    {
        $subset_users = User::whereBelongsTo($request->user(), 'creator')->get();

        return UserResource::collection($subset_users);
    }

    /**
     * Store a newly created subset user in storage.
     *
     * @param StoreSubSetUserRequest $request
     * @return UserResource
     */
    public function store(StoreSubSetUserRequest $request)
    {
        $user = User::create([
            'mobile' => $request->mobile,
            'created_by' => $request->user()->id,
        ]);

        $user->profile()->create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
        ]);

        $user->assignRole($request->role);

        $adminUserFarms = $request->user()->farms()->get();

        $user->farms()->attach($adminUserFarms);

        return new UserResource($user);
    }

    /**
     * Display the specified subset user.
     *
     * @param User $subset_user
     * @return UserResource
     */
    public function show(User $subset_user)
    {
        return new UserResource($subset_user->load('profile'));
    }

    /**
     * Update the specified subset user in storage.
     *
     * @param UpdateSubSetUserRequest $request
     * @param User $subset_user
     * @return UserResource
     */
    public function update(UpdateSubSetUserRequest $request, User $subset_user)
    {
        $subset_user->update($request->only('mobile'));

        $subset_user->profile->update($request->only('first_name', 'last_name'));

        return new UserResource($subset_user);
    }

    /**
     * Remove the specified subset user from storage.
     *
     * @param User $subset_user
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(User $subset_user)
    {
        $subset_user->delete();

        return response()->json(null, 204);
    }
}
