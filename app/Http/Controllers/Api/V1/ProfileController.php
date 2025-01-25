<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    /**
     * Get the authenticated user's profile.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Http\Resources\ProfileResource
     */
    public function show(Request $request)
    {
        $profile = $request->user()->profile;

        return new ProfileResource($profile->load('user'));
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param  \App\Http\Requests\UpdateProfileRequest  $request
     * @return \App\Http\Resources\ProfileResource
     */
    public function update(UpdateProfileRequest $request)
    {
        $profile = $request->user()->profile;

        $profile->update($request->only([
            'first_name',
            'last_name',
            'province',
            'city',
            'company',
        ]));

        if ($request->hasFile('photo')) {
            $request->user()->clearMediaCollection('photo');
            $request->user()->addMediaFromRequest('photo')->toMediaCollection('photo');
        }

        return new ProfileResource($profile->load('user'));
    }

    /**
     * Set the authenticated user's username.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Http\Resources\ProfileResource
     */
    public function setUsername(Request $request)
    {
        $request->validate([
            'username' => 'required|string|max:255|unique:users,username|regex:/^[a-zA-Z0-9_]+$/',
        ]);

        $request->user()->update(['username' => str_replace(' ', '_', $request->username)]);

        return response()->json([], JsonResponse::HTTP_OK);
    }
}
