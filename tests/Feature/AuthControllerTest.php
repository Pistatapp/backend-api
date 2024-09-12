<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use App\Notifications\VerifyMobile;
use Illuminate\Notifications\AnonymousNotifiable;
use App\Models\VerifyMobileToken;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * test users can send token to their mobile.
     *
     * @return void
     */
    public function test_users_can_send_token_to_their_mobile()
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/send', [
            'mobile' => '09107529334',
        ]);

        // Use Notification::route to send an on-demand notification
        Notification::assertSentTo(
            new AnonymousNotifiable,
            VerifyMobile::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['kavenegar'] === '09107529334';
            }
        );

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Verification token sent successfully.',
            ]);

    }

    /**
     * test users can verify their mobile token.
     *
     * @return void
     */
    public function test_users_can_verify_their_mobile_token()
    {
        $this->withoutExceptionHandling();

        VerifyMobileToken::factory()->create([
            'mobile' => '09107529334',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09107529334',
            'token' => '123456',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'username',
                    'mobile',
                    'last_activity_at',
                    'photo',
                    'token',
                    'new_user',
                    'is_admin'
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'mobile' => '09107529334',
        ]);

        $this->assertDatabaseMissing('verify_mobile_tokens', [
            'mobile' => '09107529334',
        ]);

        $this->assertAuthenticated();

        $this->assertAuthenticatedAs(User::first());

        $this->assertAuthenticatedAs(User::first(), 'sanctum');

        $this->assertAuthenticatedAs(User::first(), 'web');

    }

    /**
     * test users can't verify their mobile token with wrong token.
     *
     * @return void
     */
    public function test_users_cant_verify_their_mobile_token_with_wrong_token()
    {
        VerifyMobileToken::factory()->create([
            'mobile' => '09107529334',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09107529334',
            'token' => '654321',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'token' => 'The provided token is incorrect.',
                'retries_left',
            ]);

        $this->assertDatabaseHas('verify_mobile_tokens', [
            'mobile' => '09107529334',
        ]);

        $this->assertGuest();
    }

    /**
     * test users can't verify their mobile token with expired token.
     *
     * @return void
     */
    public function test_users_cant_verify_their_mobile_token_with_expired_token()
    {
        VerifyMobileToken::factory()->create([
            'mobile' => '09107529334',
            'token' => Hash::make('123456'),
            'created_at' => now()->subMinutes(6),
        ]);

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09107529334',
            'token' => '123456',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'token' => 'The provided token is incorrect.',
                'retries_left',
            ]);

        $this->assertDatabaseHas('verify_mobile_tokens', [
            'mobile' => '09107529334',
        ]);

        $this->assertGuest();
    }

    /**
     * test users can refresh their token.
     *
     * @return void
     */
    public function test_users_can_refresh_their_token()
    {
        $user = User::factory()->create();

        $token = $user->createToken('token-name');

        $response = $this->actingAs($user)
            ->postJson('/api/auth/refresh');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
            ]);
    }

    /**
     * test users can logout.
     *
     * @return void
     */
    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/auth/logout');

        $response->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }
}
