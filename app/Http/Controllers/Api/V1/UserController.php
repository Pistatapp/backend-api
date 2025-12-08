<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
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
        $query = User::query()->withoutRole('root');

        $workingEnvironmentId = null;
        if (!$request->input('search') && !$request->user()->hasRole('root')) {
            $workingEnvironment = $request->user()->workingEnvironment();
            $workingEnvironmentId = $workingEnvironment?->id;
            $request->merge(['_working_environment_id' => $workingEnvironmentId]);
        }

        if ($search = $request->input('search')) {
            $query->search($search, ['mobile'])->withoutRole('super-admin');
        } elseif ($request->user()->hasAnyRole(['admin', 'super-admin'])) {
            $query->where('created_by', $request->user()->id);
        }

        $query->where('id', '!=', $request->user()->id);

        // Eager-load farms relationship when working environment ID is set to avoid N+1 queries
        if ($workingEnvironmentId) {
            $query->with('farms');
        }

        $users = $search ? $query->get() : $query->simplePaginate();

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

        $user->profile()->create($request->only('first_name', 'last_name'));

        $user->farms()->attach($request->farms, [
            'role' => $request->role,
            'is_owner' => false,
        ]);

        return new UserResource($user);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return new UserResource($user->load('profile', 'farms'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $user->update($request->only('mobile'));

        $user->profile->update($request->only('first_name', 'last_name'));

        $user->syncRoles($request->role);

        $user->farms()->sync($request->farms, [
            'role' => $request->role,
            'is_owner' => false,
        ]);

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
}
