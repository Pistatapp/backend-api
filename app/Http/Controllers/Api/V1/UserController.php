<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::role(['super-admin', 'admin'])->withCount('gpsDevices');

        if (request()->query('without_pagination')) {
            return UserResource::collection($users->get());
        }

        return UserResource::collection($users->simplePaginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile:zero|unique:users,mobile',
        ]);

        $user = User::create($request->only('mobile'));

        $user->assignRole('admin');

        $user->profile()->create($request->only('first_name', 'last_name'));

        return new UserResource($user);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return new UserResource($user->load('profile'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'mobile' => 'required|ir_mobile:zero|unique:users,mobile,' . $user->id,
        ]);

        $user->update($request->only('mobile'));

        $user->profile->update($request->only('first_name', 'last_name'));

        return new UserResource($user->fresh());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([], JsonResponse::HTTP_GONE);
    }
}
