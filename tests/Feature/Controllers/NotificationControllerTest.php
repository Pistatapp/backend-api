<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create some test notifications
        $this->user->notifications()->create([
            'id' => '123e4567-e89b-12d3-a456-426614174000',
            'type' => 'App\Notifications\TestNotification',
            'data' => ['message' => 'Test notification 1'],
            'read_at' => null
        ]);

        $this->user->notifications()->create([
            'id' => '123e4567-e89b-12d3-a456-426614174001',
            'type' => 'App\Notifications\TestNotification',
            'data' => ['message' => 'Test notification 2'],
            'read_at' => null
        ]);
    }

    #[Test]
    public function it_returns_unread_notifications_for_authenticated_user()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'data',
                        'created_at'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_marks_specific_notification_as_read()
    {
        $notification = $this->user->notifications->first();

        $response = $this->actingAs($this->user)
            ->postJson("/api/notifications/{$notification->id}/mark_as_read");

        $response->assertStatus(200)
            ->assertJson(['message' => __('Notification marked as read.')]);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'read_at' => now()
        ]);
    }

    #[Test]
    public function it_marks_all_notifications_as_read()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/mark_all_as_read');

        $response->assertStatus(200)
            ->assertJson(['message' => __('All notifications marked as read.')]);

        $this->user->refresh();
        $this->assertEquals(0, $this->user->unreadNotifications->count());
    }

    #[Test]
    public function it_returns_404_for_nonexistent_notification()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/nonexistent-id/mark_as_read');

        $response->assertStatus(404);
    }

    #[Test]
    public function it_prevents_unauthorized_access_to_notifications()
    {
        $response = $this->getJson('/api/notifications');
        $response->assertStatus(401);
    }

    #[Test]
    public function it_prevents_user_from_marking_other_users_notifications_as_read()
    {
        $otherUser = User::factory()->create();
        $notification = $otherUser->notifications()->create([
            'id' => '123e4567-e89b-12d3-a456-426614174002',
            'type' => 'App\Notifications\TestNotification',
            'data' => ['message' => 'Other user notification'],
            'read_at' => null
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/notifications/{$notification->id}/mark_as_read");

        $response->assertStatus(404);
    }
}
