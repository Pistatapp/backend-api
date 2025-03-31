<?php

namespace Tests\Unit\Notifications;

use Tests\TestCase;
use App\Models\Tractor;
use App\Models\User;
use App\Notifications\TractorInactivityNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class TractorInactivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tractor $tractor;
    private User $user;
    private Carbon $date;
    private Carbon $lastActivity;
    private int $threshold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tractor = Tractor::factory()->create();
        $this->user = User::factory()->create();
        $this->date = now();
        $this->lastActivity = now()->subHours(2);
        $this->threshold = 1; // 1 hour threshold
    }

    #[Test]
    public function it_creates_notification_with_correct_data()
    {
        $notification = new TractorInactivityNotification(
            $this->tractor,
            $this->lastActivity,
            $this->threshold,
            $this->date
        );

        $data = $notification->toArray($this->user);

        $this->assertEquals($this->lastActivity->format('Y-m-d H:i:s'), $data['last_activity']);
        $this->assertEquals($this->threshold, $data['threshold']);
        $this->assertEquals('warning', $data['color']);
        $this->assertStringContainsString($this->tractor->name, $data['message']);
        $this->assertStringContainsString($this->threshold, $data['message']);
        $this->assertStringContainsString($this->lastActivity->format('H:i'), $data['message']);
        $this->assertStringContainsString(jdate($this->date)->format('Y/m/d'), $data['message']);
    }

    #[Test]
    public function it_sends_notification_through_database_and_firebase()
    {
        Notification::fake();

        $this->user->notify(new TractorInactivityNotification(
            $this->tractor,
            $this->lastActivity,
            $this->threshold,
            $this->date
        ));

        Notification::assertSentTo(
            $this->user,
            TractorInactivityNotification::class,
            function ($notification) {
                return $notification->via($this->user) === ['database', 'firebase'];
            }
        );
    }

    #[Test]
    public function it_formats_firebase_message_correctly()
    {
        $notification = new TractorInactivityNotification(
            $this->tractor,
            $this->lastActivity,
            $this->threshold,
            $this->date
        );

        $firebaseMessage = $notification->toFireBase($this->user);

        $this->assertEquals('Tractor Inactivity Warning', $firebaseMessage->title);
        $this->assertStringContainsString($this->tractor->name, $firebaseMessage->body);
        $this->assertEquals($this->lastActivity->format('Y-m-d H:i:s'), $firebaseMessage->data['last_activity']);
        $this->assertEquals($this->threshold, $firebaseMessage->data['threshold']);
        $this->assertEquals('warning', $firebaseMessage->data['color']);
    }
}
