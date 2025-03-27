<?php

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()
            ->has(Profile::factory())
            ->create();
    }

    #[Test]
    public function user_can_view_their_profile()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user' => [
                        'id',
                        'username',
                        'mobile',
                    ],
                    'first_name',
                    'last_name',
                    'province',
                    'city',
                    'company',
                    'photo',
                ]
            ]);
    }

    #[Test]
    public function user_can_update_their_profile()
    {
        $profileData = [
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'province' => $this->faker->state,
            'city' => $this->faker->city,
            'company' => $this->faker->company,
        ];

        $response = $this->actingAs($this->user)
            ->putJson('/api/profile', $profileData);

        $response->assertOk();
        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->user->id,
            'first_name' => $profileData['first_name'],
            'last_name' => $profileData['last_name'],
            'province' => $profileData['province'],
            'city' => $profileData['city'],
            'company' => $profileData['company'],
        ]);
    }

    #[Test]
    public function user_can_update_their_profile_with_photo()
    {
        Storage::fake('local');

        $profileData = [
            'first_name' => $this->faker->firstName,
            'last_name' => $this->faker->lastName,
            'province' => $this->faker->state,
            'city' => $this->faker->city,
            'company' => $this->faker->company,
            'photo' => UploadedFile::fake()->image('avatar.jpg'),
        ];

        $response = $this->actingAs($this->user)
            ->putJson('/api/profile', $profileData);

        $response->assertOk();
        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->user->id,
            'first_name' => $profileData['first_name'],
        ]);

        $this->assertNotNull($this->user->fresh()->getFirstMedia('photo'));
    }

    #[Test]
    public function user_can_set_username()
    {
        $username = 'valid_username_123';

        $response = $this->actingAs($this->user)
            ->patchJson('/api/username', [
                'username' => $username
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'username' => $username,
        ]);
    }

    #[Test]
    public function username_must_be_unique()
    {
        $existingUser = User::factory()->create();

        $response = $this->actingAs($this->user)
            ->patchJson('/api/username', [
                'username' => $existingUser->username
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    #[Test]
    public function username_must_be_valid_format()
    {
        $response = $this->actingAs($this->user)
            ->patchJson('/api/username', [
                'username' => 'invalid username!'
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }
}
