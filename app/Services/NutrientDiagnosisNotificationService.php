<?php

namespace App\Services;

use App\Models\NutrientDiagnosisRequest;
use App\Models\User;
use App\Notifications\NewNutrientDiagnosisRequest;

class NutrientDiagnosisNotificationService
{
    /**
     * Notify root users about a new nutrient diagnosis request.
     *
     * @param NutrientDiagnosisRequest $request
     * @return void
     */
    public function notifyRootUsers(NutrientDiagnosisRequest $request): void
    {
        User::role('root')->each(function ($rootUser) use ($request) {
            $rootUser->notify(new NewNutrientDiagnosisRequest($request));
        });
    }
}
