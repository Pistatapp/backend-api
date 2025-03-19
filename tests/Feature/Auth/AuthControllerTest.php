<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Farm;
use App\Notifications\VerifyMobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /** @test */
    public function it_can_send_verification_token()
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/send', [
            'mobile' => '09123456789'
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => __('Verification token sent successfully.')]);

        $user = User::where('mobile', '09123456789')->first();
        $this->assertNotNull($user);

        Notification::assertSentTo($user, VerifyMobile::class);
    }

    /** @test */
    public function it_validates_mobile_number_format()
    {
        $response = $this->postJson('/api/auth/send', [
            'mobile' => 'invalid-number'
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function it_can_verify_token_and_login()
    {
        $token = '123456';
        $user = User::factory()->create([
            'mobile' => '09123456789',
            'password' => $token, // Password is the token itself
            'password_expires_at' => now()->addMinutes(5)
        ]);

        $this->withoutExceptionHandling();

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09123456789',
            'token' => $token,
            'fcm_token' => 'test-fcm-token'
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertEquals('test-fcm-token', $user->fcm_token);
    }

    /** @test */
    public function it_throttles_login_attempts()
    {
        $user = User::factory()->create([
            'mobile' => '09123456789',
            'password' => '123456'
        ]);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/auth/verify', [
                'mobile' => '09123456789',
                'token' => 'wrong-token',
            ]);
        }

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09123456789',
            'token' => 'wrong-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /** @test */
    public function it_can_refresh_token()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/refresh', [
            'fcm_token' => 'new-fcm-token'
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'permissions'
            ]);

        $user->refresh();
        $this->assertEquals('new-fcm-token', $user->fcm_token);
    }

    /** @test */
    public function it_can_logout()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->assertAuthenticated('sanctum');

        $response = $this->postJson('/api/auth/logout');

        $response->assertOk()
            ->assertJson(['message' => __('Logged out successfully.')]);

        $this->assertFalse($user->tokens()->exists());
    }

    /** @test */
    public function it_can_get_user_permissions()
    {
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'role',
                'permissions'
            ]);
    }

    /** @test */
    public function it_returns_working_environment_permissions_when_available()
    {
        $user = User::factory()->create();
        $farm = Farm::factory()->create();

        $farm->users()->attach($user, [
            'role' => 'admin',
        ]);

        $user->update([
            'preferences->working_environment' => $farm->id
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions');

        $response->assertOk()
            ->assertJsonStructure([
                'role',
                'permissions'
            ]);

        $this->assertEquals('admin', $response->json('role'));
    }

    /** @test */
    public function it_returns_root_permissions_for_root_user()
    {
        $user = User::factory()->create();
        $user->assignRole('root');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/permissions');

        $response->assertOk()
            ->assertJson([
                'role' => 'root'
            ]);
    }

    /** @test */
    public function it_validates_token_length()
    {
        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09123456789',
            'token' => '12345' // 5 digits instead of required 6
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    /** @test */
    public function it_creates_user_profile_on_first_verification()
    {
        $token = '123456';
        $user = User::factory()->create([
            'mobile' => '09123456789',
            'password' => $token, // Password is the token itself
            'password_expires_at' => now()->addMinutes(5)
        ]);

        $this->assertFalse($user->hasVerifiedMobile());
        $this->assertNull($user->profile);

        $this->withoutExceptionHandling();

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09123456789',
            'token' => $token
        ]);

        $response->assertOk();

        $user->refresh();
        $this->assertTrue($user->hasVerifiedMobile());
        $this->assertNotNull($user->profile);
        $this->assertTrue($user->hasRole('admin'));
    }
}
