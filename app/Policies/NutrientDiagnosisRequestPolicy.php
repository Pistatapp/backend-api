<?php

namespace App\Policies;

use App\Models\Farm;
use App\Models\NutrientDiagnosisRequest;
use App\Models\User;

class NutrientDiagnosisRequestPolicy
{
    /**
     * Determine whether the user can view any nutrient diagnosis requests.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the nutrient diagnosis request.
     * Root users and users belonging to the request's farm can view.
     */
    public function view(User $user, NutrientDiagnosisRequest $nutrientDiagnosisRequest): bool
    {
        return $user->hasRole('root') ||
               $nutrientDiagnosisRequest->farm->users->contains($user->id);
    }

    /**
     * Determine whether the user can create nutrient diagnosis requests.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the nutrient diagnosis request.
     * Only root users can update requests.
     */
    public function update(User $user, NutrientDiagnosisRequest $nutrientDiagnosisRequest): bool
    {
        return $user->hasRole('root');
    }

    /**
     * Determine whether the user can delete the nutrient diagnosis request.
     * Root users can delete any request, while regular users can only delete their own pending requests.
     */
    public function delete(User $user, NutrientDiagnosisRequest $nutrientDiagnosisRequest): bool
    {
        return $user->hasRole('root') ||
               ($nutrientDiagnosisRequest->user_id === $user->id &&
                $nutrientDiagnosisRequest->status === 'pending');
    }

    /**
     * Determine whether the user can respond to the nutrient diagnosis request.
     * Only root users can respond to requests.
     */
    public function respond(User $user, NutrientDiagnosisRequest $nutrientDiagnosisRequest): bool
    {
        return $user->hasRole('root');
    }
}
