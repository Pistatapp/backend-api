<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPreferenceControllerTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private array $defaultPreferences = [
        'language' => 'en',
        'theme' => 'light',
        'notifications_enabled' => true,
        'working_environment' => null
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function unauthorized_users_cannot_access_preferences()
    {
        $response = $this->getJson('/api/preferences');
        $response->assertUnauthorized();

        $response = $this->putJson('/api/preferences', ['preferences' => ['theme' => 'dark']]);
        $response->assertUnauthorized();

        $response = $this->deleteJson('/api/preferences');
        $response->assertUnauthorized();
    }

    /** @test */
    public function user_can_get_their_preferences()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/preferences');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['preferences']
            ])
            ->assertJson([
                'data' => [
                    'preferences' => $this->defaultPreferences
                ]
            ]);
    }

    /** @test */
    public function user_can_update_single_preference()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/preferences', [
            'preferences' => [
                'theme' => 'dark'
            ]
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['preferences']
            ])
            ->assertJson([
                'message' => __('messages.preferences.updated'),
                'data' => [
                    'preferences' => array_merge(
                        $this->defaultPreferences,
                        ['theme' => 'dark']
                    )
                ]
            ]);
    }

    /** @test */
    public function user_can_update_multiple_preferences()
    {
        Sanctum::actingAs($this->user);

        $preferences = [
            'language' => 'fa',
            'theme' => 'dark',
            'notifications_enabled' => false
        ];

        $response = $this->putJson('/api/preferences', [
            'preferences' => $preferences
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['preferences']
            ])
            ->assertJson([
                'message' => __('messages.preferences.updated'),
                'data' => [
                    'preferences' => array_merge(
                        ['working_environment' => null],
                        $preferences
                    )
                ]
            ]);
    }

    /** @test */
    public function user_can_reset_preferences()
    {
        Sanctum::actingAs($this->user);

        // First set some custom preferences
        $this->user->preferences = [
            'theme' => 'dark',
            'language' => 'fa'
        ];
        $this->user->save();

        $response = $this->deleteJson('/api/preferences');

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['preferences']
            ])
            ->assertJson([
                'message' => __('messages.preferences.reset'),
                'data' => [
                    'preferences' => $this->defaultPreferences
                ]
            ]);

        $this->assertEquals($this->defaultPreferences, $this->user->fresh()->preferences);
    }

    /** @test */
    public function it_validates_language_preference()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/preferences', [
            'preferences' => [
                'language' => 'invalid'
            ]
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['preferences.language'])
            ->assertJson([
                'message' => __('messages.preferences.invalid_language')
            ]);
    }

    /** @test */
    public function it_validates_theme_preference()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/preferences', [
            'preferences' => [
                'theme' => 'invalid'
            ]
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['preferences.theme'])
            ->assertJson([
                'message' => __('messages.preferences.invalid_theme')
            ]);
    }

    /** @test */
    public function it_validates_notifications_enabled_preference()
    {
        Sanctum::actingAs($this->user);

        $response = $this->putJson('/api/preferences', [
            'preferences' => [
                'notifications_enabled' => 'not-a-boolean'
            ]
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['preferences.notifications_enabled'])
            ->assertJson([
                'message' => 'The preferences.notifications enabled field must be true or false.'
            ]);
    }
}
