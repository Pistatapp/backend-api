<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SetDefaultUserPreferencesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultPreferences = [
            'language' => 'en',
            'theme' => 'light',
            'notifications_enabled' => true,
            'working_environment' => null
        ];

        User::query()
            ->whereNull('preferences')
            ->orWhere('preferences', '=', '[]')
            ->orWhere('preferences->working_environment', '=', null)
            ->each(function ($user) use ($defaultPreferences) {
                $currentPreferences = $user->preferences ?? [];
                // Preserve existing working_environment if it exists
                if (isset($currentPreferences['working_environment'])) {
                    $defaultPreferences['working_environment'] = $currentPreferences['working_environment'];
                }
                $user->preferences = $defaultPreferences;
                $user->save();
            });
    }
}
