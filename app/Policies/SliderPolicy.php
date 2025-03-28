<?php

namespace App\Policies;

use App\Models\Slider;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SliderPolicy
{
    /**
     * Determine whether the user can view any sliders.
     */
    public function viewAny(?User $user): bool
    {
        return true; // Public access for viewing sliders
    }

    /**
     * Determine whether the user can view the slider.
     */
    public function view(?User $user, Slider $slider): bool
    {
        return true; // Public access for viewing individual slider
    }

    /**
     * Determine whether the user can create sliders.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can update the slider.
     */
    public function update(User $user, Slider $slider): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the slider.
     */
    public function delete(User $user, Slider $slider): bool
    {
        return $user->hasRole('root');
    }
}
