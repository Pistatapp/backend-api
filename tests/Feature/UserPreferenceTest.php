<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPreferenceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function new_users_get_default_preferences()
    {
        $user = User::factory()->create();

        $this->assertEquals([
            'language' => config('app.locale', 'en'),
            'theme' => 'light',
            'notifications_enabled' => true,
            'working_environment' => null
        ], $user->fresh()->preferences);
    }

    /** @test */
    public function preferences_can_be_set_to_null()
    {
        $user = User::factory()->create();
        $user->preferences = null;
        $user->save();

        $this->assertNull($user->fresh()->preferences);
    }

    /** @test */
    public function user_can_set_language_preference()
    {
        // Create a user
        $user = User::factory()->create();

        // Set language preference
        $user->preferences = ['language' => 'fa'];
        $user->save();

        // Verify preference was saved
        $this->assertEquals('fa', $user->fresh()->preferences['language']);
    }

    /** @test */
    public function user_can_update_multiple_preferences()
    {
        $user = User::factory()->create();

        $preferences = [
            'language' => 'en',
            'theme' => 'dark',
            'notifications_enabled' => true
        ];

        $user->preferences = $preferences;
        $user->save();

        $freshUser = $user->fresh();
        $this->assertEquals($preferences, $freshUser->preferences);
    }

    /** @test */
    public function preferences_are_json_castable()
    {
        $user = User::factory()->create([
            'preferences' => ['theme' => 'light']
        ]);

        $this->assertIsArray($user->preferences);
        $this->assertEquals('light', $user->preferences['theme']);
    }

    /** @test */
    public function can_update_single_preference_without_affecting_others()
    {
        $user = User::factory()->create([
            'preferences' => [
                'language' => 'en',
                'theme' => 'light',
                'notifications_enabled' => true
            ]
        ]);

        // Update just the theme
        $preferences = $user->preferences;
        $preferences['theme'] = 'dark';
        $user->preferences = $preferences;
        $user->save();

        $freshUser = $user->fresh();
        $this->assertEquals('dark', $freshUser->preferences['theme']);
        $this->assertEquals('en', $freshUser->preferences['language']);
        $this->assertTrue($freshUser->preferences['notifications_enabled']);
    }

    /** @test */
    public function preferences_can_be_null()
    {
        $user = User::factory()->create([
            'preferences' => null
        ]);

        $this->assertNull($user->preferences);
    }
}
