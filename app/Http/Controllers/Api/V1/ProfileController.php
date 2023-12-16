<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;

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

        return new ProfileResource($profile);
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

        $request->user()->update(['username' => $request->username]);

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

        return new ProfileResource($profile->refresh());
    }
}
