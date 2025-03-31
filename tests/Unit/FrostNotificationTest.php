<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\FrostNotification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FrostNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private float $temperature;
    private string $date;
    private int $days;
    private FrostNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->temperature = -2.5;
        $this->date = '1402/10/15';
        $this->days = 3;

        $this->notification = new FrostNotification(
            $this->temperature,
            $this->date,
            $this->days
        );
    }

    #[Test]
    public function it_sends_notification_through_database_and_firebase()
    {
        $channels = $this->notification->via($this->user);

        $this->assertEquals(['database', 'firebase'], $channels);
    }

    #[Test]
    public function it_formats_array_representation_correctly()
    {
        $data = $this->notification->toArray($this->user);

        $this->assertEquals($this->temperature, $data['temperature']);
        $this->assertEquals($this->date, $data['date']);
        $this->assertEquals($this->days, $data['days']);
        $this->assertStringContainsString(
            $this->days,
            $data['message']
        );
    }

    #[Test]
    public function it_formats_firebase_message_correctly()
    {
        $firebaseMessage = $this->notification->toFirebase($this->user);

        $this->assertEquals('Frost Warning', $firebaseMessage->title);
        $this->assertStringContainsString($this->days, $firebaseMessage->body);
        $this->assertEquals($this->temperature, $firebaseMessage->data['temperature']);
        $this->assertEquals($this->date, $firebaseMessage->data['date']);
        $this->assertEquals($this->days, $firebaseMessage->data['days']);
    }
}
