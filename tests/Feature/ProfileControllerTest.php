<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'username' => 'testuser',
            'mobile' => '09123456789',
        ]);

        $this->profile = Profile::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
            'personnel_number' => '123456789',
        ]);
    }

    /**
     * Test unauthenticated user cannot access profile.
     */
    public function test_unauthenticated_user_cannot_access_profile(): void
    {
        $response = $this->getJson('/api/profile');

        $response->assertUnauthorized();
    }

    /**
     * Test authenticated user can view their own profile.
     */
    public function test_authenticated_user_can_view_their_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/profile');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'user' => [
                    'id',
                    'username',
                    'mobile',
                ],
                'name',
                'province',
                'city',
                'company',
                'personnel_number',
                'image',
            ],
        ]);

        $response->assertJson([
            'data' => [
                'id' => $this->profile->id,
                'user' => [
                    'id' => $this->user->id,
                    'username' => 'testuser',
                    'mobile' => '09123456789',
                ],
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
                'personnel_number' => '123456789',
            ],
        ]);
    }

    /**
     * Test user can update their profile.
     */
    public function test_user_can_update_their_profile(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'Jane Doe',
                'province' => 'Isfahan',
                'city' => 'Isfahan',
                'company' => 'New Company',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'name' => 'Jane Doe',
                'province' => 'Isfahan',
                'city' => 'Isfahan',
                'company' => 'New Company',
            ],
        ]);

        $this->assertDatabaseHas('profiles', [
            'id' => $this->profile->id,
            'name' => 'Jane Doe',
            'province' => 'Isfahan',
            'city' => 'Isfahan',
            'company' => 'New Company',
        ]);
    }

    /**
     * Test user cannot update personnel number.
     */
    public function test_user_cannot_update_personnel_number(): void
    {
        $originalPersonnelNumber = $this->profile->personnel_number;

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
                'personnel_number' => '987654321',
            ]);

        $response->assertStatus(200);

        // Personnel number should remain unchanged even if provided
        $this->profile->refresh();
        $this->assertEquals($originalPersonnelNumber, $this->profile->personnel_number);
        $this->assertNotEquals('987654321', $this->profile->personnel_number);
    }

    /**
     * Test profile update requires name field.
     */
    public function test_profile_update_requires_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * Test profile update requires province field.
     */
    public function test_profile_update_requires_province(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'city' => 'Tehran',
                'company' => 'Test Company',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['province']);
    }

    /**
     * Test profile update requires city field.
     */
    public function test_profile_update_requires_city(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'company' => 'Test Company',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['city']);
    }

    /**
     * Test profile update requires company field.
     */
    public function test_profile_update_requires_company(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company']);
    }

    /**
     * Test profile update validates name max length.
     */
    public function test_profile_update_validates_name_max_length(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => str_repeat('a', 256),
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    /**
     * Test profile update validates province max length.
     */
    public function test_profile_update_validates_province_max_length(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => str_repeat('a', 256),
                'city' => 'Tehran',
                'company' => 'Test Company',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['province']);
    }

    /**
     * Test profile update validates city max length.
     */
    public function test_profile_update_validates_city_max_length(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => str_repeat('a', 256),
                'company' => 'Test Company',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['city']);
    }

    /**
     * Test profile update validates company max length.
     */
    public function test_profile_update_validates_company_max_length(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['company']);
    }

    /**
     * Test profile update accepts valid image file.
     */
    public function test_profile_update_accepts_valid_image(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
                'image' => $image,
            ]);

        $response->assertStatus(200);
    }

    /**
     * Test profile update rejects invalid image file.
     */
    public function test_profile_update_rejects_invalid_image(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
                'image' => $file,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image']);
    }

    /**
     * Test profile update rejects image file larger than 1MB.
     */
    public function test_profile_update_rejects_large_image(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('avatar.jpg')->size(2048); // 2MB

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'John Doe',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Test Company',
                'image' => $image,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image']);
    }

    /**
     * Test user can set username.
     */
    public function test_user_can_set_username(): void
    {
        /** @var User $userWithoutUsername */
        $userWithoutUsername = User::factory()->create(['username' => null]);

        $response = $this->actingAs($userWithoutUsername, 'sanctum')
            ->patchJson('/api/username', [
                'username' => 'newusername',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => __('Username updated successfully.'),
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $userWithoutUsername->id,
            'username' => 'newusername',
        ]);
    }

    /**
     * Test username must be unique.
     */
    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'existinguser']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/username', [
                'username' => 'existinguser',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }

    /**
     * Test username must match regex pattern.
     */
    public function test_username_must_match_regex_pattern(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/username', [
                'username' => 'invalid-username-with-dashes',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }

    /**
     * Test username can contain underscores.
     */
    public function test_username_can_contain_underscores(): void
    {
        /** @var User $userWithoutUsername */
        $userWithoutUsername = User::factory()->create(['username' => null]);

        $response = $this->actingAs($userWithoutUsername, 'sanctum')
            ->patchJson('/api/username', [
                'username' => 'user_name_123',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $userWithoutUsername->id,
            'username' => 'user_name_123',
        ]);
    }

    /**
     * Test username spaces are replaced with underscores.
     */
    public function test_username_spaces_are_replaced_with_underscores(): void
    {
        /** @var User $userWithoutUsername */
        $userWithoutUsername = User::factory()->create(['username' => null]);

        $response = $this->actingAs($userWithoutUsername, 'sanctum')
            ->patchJson('/api/username', [
                'username' => 'user name 123',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $userWithoutUsername->id,
            'username' => 'user_name_123',
        ]);
    }

    /**
     * Test username is required.
     */
    public function test_username_is_required(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/username', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }

    /**
     * Test username max length validation.
     */
    public function test_username_max_length_validation(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson('/api/username', [
                'username' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username']);
    }

    /**
     * Test user without profile returns error.
     */
    public function test_user_without_profile_returns_error(): void
    {
        /** @var User $userWithoutProfile */
        $userWithoutProfile = User::factory()->create();

        $response = $this->actingAs($userWithoutProfile, 'sanctum')
            ->getJson('/api/profile');

        // Should return error when profile doesn't exist
        $response->assertStatus(500);
    }

    /**
     * Test profile update only updates allowed fields.
     */
    public function test_profile_update_only_updates_allowed_fields(): void
    {
        $originalPersonnelNumber = $this->profile->personnel_number;

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'Updated Name',
                'province' => 'Updated Province',
                'city' => 'Updated City',
                'company' => 'Updated Company',
                'personnel_number' => '999999999', // Should be ignored
            ]);

        $response->assertStatus(200);

        $this->profile->refresh();
        $this->assertEquals('Updated Name', $this->profile->name);
        $this->assertEquals('Updated Province', $this->profile->province);
        $this->assertEquals('Updated City', $this->profile->city);
        $this->assertEquals('Updated Company', $this->profile->company);
        // Personnel number should remain unchanged even if provided
        $this->assertEquals($originalPersonnelNumber, $this->profile->personnel_number);
        $this->assertNotEquals('999999999', $this->profile->personnel_number);
    }

    /**
     * Test profile response includes user information.
     */
    public function test_profile_response_includes_user_information(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/profile');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertArrayHasKey('user', $data);
        $this->assertEquals($this->user->id, $data['user']['id']);
        $this->assertEquals($this->user->username, $data['user']['username']);
        $this->assertEquals($this->user->mobile, $data['user']['mobile']);
    }
}
