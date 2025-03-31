<?php

namespace Tests\Unit;

use App\Notifications\OilSprayNotification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class OilSprayNotificationTest extends TestCase
{
    private string $startDate;
    private string $endDate;
    private int $requiredHours;
    private int $actualHours;
    private OilSprayNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->startDate = '1402/10/01';
        $this->endDate = '1402/10/15';
        $this->requiredHours = 100;
        $this->actualHours = 80;

        $this->notification = new OilSprayNotification(
            $this->startDate,
            $this->endDate,
            $this->requiredHours,
            $this->actualHours
        );
    }

    #[Test]
    public function it_sends_notification_through_database_and_firebase()
    {
        $channels = $this->notification->via(new \stdClass());

        $this->assertEquals(['database', 'firebase'], $channels);
    }

    #[Test]
    public function it_formats_array_representation_correctly()
    {
        $data = $this->notification->toArray(new \stdClass());

        $this->assertEquals($this->startDate, $data['start_date']);
        $this->assertEquals($this->endDate, $data['end_date']);
        $this->assertEquals($this->requiredHours, $data['required_hours']);
        $this->assertEquals($this->actualHours, $data['actual_hours']);
        $this->assertStringContainsString(
            (string)$this->actualHours,
            $data['message']
        );
    }

    #[Test]
    public function it_formats_firebase_message_correctly()
    {
        $firebaseMessage = $this->notification->toFirebase(new \stdClass());

        $this->assertEquals('Volk Oil Spray Warning', $firebaseMessage->title);
        $this->assertStringContainsString($this->startDate, $firebaseMessage->body);
        $this->assertStringContainsString($this->endDate, $firebaseMessage->body);
        $this->assertStringContainsString((string)$this->actualHours, $firebaseMessage->body);
        $this->assertEquals($this->startDate, $firebaseMessage->data['start_date']);
        $this->assertEquals($this->endDate, $firebaseMessage->data['end_date']);
        $this->assertEquals($this->requiredHours, $firebaseMessage->data['required_hours']);
        $this->assertEquals($this->actualHours, $firebaseMessage->data['actual_hours']);
    }
}
