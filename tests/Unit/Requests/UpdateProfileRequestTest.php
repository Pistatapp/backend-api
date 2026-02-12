<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UpdateProfileRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Test validation rules for UpdateProfileRequest.
     */
    public function test_validation_rules(): void
    {
        $request = new UpdateProfileRequest();
        $rules = $request->rules();

        $this->assertArrayHasKey('name', $rules);
        $this->assertArrayHasKey('province', $rules);
        $this->assertArrayHasKey('city', $rules);
        $this->assertArrayHasKey('company', $rules);
        $this->assertArrayHasKey('image', $rules);

        $this->assertContains('required', $rules['name']);
        $this->assertContains('required', $rules['province']);
        $this->assertContains('required', $rules['city']);
        $this->assertContains('required', $rules['company']);
        $this->assertContains('nullable', $rules['image']);
    }

    /**
     * Test name is required.
     */
    public function test_name_is_required(): void
    {
        $validator = Validator::make([
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    /**
     * Test province is required.
     */
    public function test_province_is_required(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('province', $validator->errors()->toArray());
    }

    /**
     * Test city is required.
     */
    public function test_city_is_required(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('city', $validator->errors()->toArray());
    }

    /**
     * Test company is required.
     */
    public function test_company_is_required(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => 'Tehran',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('company', $validator->errors()->toArray());
    }

    /**
     * Test name must be a string.
     */
    public function test_name_must_be_string(): void
    {
        $validator = Validator::make([
            'name' => 123,
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    /**
     * Test name max length validation.
     */
    public function test_name_max_length_validation(): void
    {
        $validator = Validator::make([
            'name' => str_repeat('a', 256),
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }

    /**
     * Test province max length validation.
     */
    public function test_province_max_length_validation(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => str_repeat('a', 256),
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('province', $validator->errors()->toArray());
    }

    /**
     * Test city max length validation.
     */
    public function test_city_max_length_validation(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => str_repeat('a', 256),
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('city', $validator->errors()->toArray());
    }

    /**
     * Test company max length validation.
     */
    public function test_company_max_length_validation(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => str_repeat('a', 256),
        ], (new UpdateProfileRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('company', $validator->errors()->toArray());
    }

    /**
     * Test image is nullable.
     */
    public function test_image_is_nullable(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertFalse($validator->fails());
    }

    /**
     * Test valid data passes validation.
     */
    public function test_valid_data_passes_validation(): void
    {
        $validator = Validator::make([
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ], (new UpdateProfileRequest())->rules());

        $this->assertFalse($validator->fails());
    }

    /**
     * Test authorization returns true for authenticated user.
     */
    public function test_authorization_returns_true_for_authenticated_user(): void
    {
        $request = UpdateProfileRequest::create('/api/profile', 'PUT', [
            'name' => 'John Doe',
            'province' => 'Tehran',
            'city' => 'Tehran',
            'company' => 'Test Company',
        ]);

        $request->setUserResolver(function () {
            return $this->user;
        });

        $this->assertTrue($request->authorize());
    }
}
