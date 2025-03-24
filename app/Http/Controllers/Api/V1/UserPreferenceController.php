<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    /**
     * Default user preferences
     */
    private array $defaultPreferences = [
        'language' => 'en',
        'theme' => 'light',
        'notifications_enabled' => true,
        'working_environment' => null
    ];

    /**
     * Get all preferences for the authenticated user
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'preferences' => Auth::user()->preferences ?? $this->defaultPreferences
            ]
        ]);
    }

    /**
     * Update user preferences. Can handle both single and multiple preference updates.
     */
    public function update(UpdateUserPreferencesRequest $request): JsonResponse
    {
        $user = Auth::user();

        // Get current preferences or initialize with defaults
        $currentPreferences = $user->preferences ?? $this->defaultPreferences;

        // Merge new preferences with existing ones
        $newPreferences = array_merge(
            $currentPreferences,
            $request->validated()['preferences'] ?? []
        );

        // Update user preferences
        $user->preferences = $newPreferences;
        $user->save();

        return response()->json([
            'message' => __('messages.preferences.updated'),
            'data' => [
                'preferences' => $newPreferences
            ]
        ]);
    }

    /**
     * Reset user preferences to default values
     */
    public function reset(): JsonResponse
    {
        $user = Auth::user();
        $user->preferences = $this->defaultPreferences;
        $user->save();

        return response()->json([
            'message' => __('messages.preferences.reset'),
            'data' => [
                'preferences' => $this->defaultPreferences
            ]
        ]);
    }
}
