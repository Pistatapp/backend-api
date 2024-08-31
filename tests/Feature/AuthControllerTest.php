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

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Verification token sent successfully.',
            ]);

        // Use Notification::route to send an on-demand notification
        Notification::assertSentTo(
            new AnonymousNotifiable,
            VerifyMobile::class,
            function ($notification, $channels, $notifiable) {
                return $notifiable->routes['kavenegar'] === '09107529334';
            }
        );
    }

    /**
     * test users can verify their mobile token.
     *
     * @return void
     */
    public function test_users_can_verify_their_mobile_token()
    {
        VerifyMobileToken::factory()->create([
            'mobile' => '09107529334',
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/verify', [
            'mobile' => '09107529334',
            'token' => '123456',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'username',
                    'mobile',
                    'mobile_verified_at',
                    'last_activity_at',
                    'avatar',
                    'is_admin',
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'mobile' => '09107529334',
            'mobile_verified_at' => now(),
        ]);

        $this->assertDatabaseMissing('verify_mobile_tokens', [
            'mobile' => '09107529334',
        ]);

        $this->assertAuthenticated();

        $this->assertAuthenticatedAs(User::first());

        $this->assertAuthenticatedAs(User::first(), 'sanctum');

        $this->assertAuthenticatedAs(User::first(), 'web');

    }
}
