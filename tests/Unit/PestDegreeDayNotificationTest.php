<?php

namespace Tests\Unit;

use App\Notifications\PestDegreeDayNotification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PestDegreeDayNotificationTest extends TestCase
{
    private string $pest;
    private string $startDate;
    private string $endDate;
    private float $requiredDegreeDays;
    private float $actualDegreeDays;
    private PestDegreeDayNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pest = 'codling_moth';
        $this->startDate = '1402/10/01';
        $this->endDate = '1402/10/15';
        $this->requiredDegreeDays = 100.0;
        $this->actualDegreeDays = 75.0;

        $this->notification = new PestDegreeDayNotification(
            $this->pest,
            $this->startDate,
            $this->endDate,
            $this->requiredDegreeDays,
            $this->actualDegreeDays
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

        $this->assertEquals($this->pest, $data['pest']);
        $this->assertEquals($this->startDate, $data['start_date']);
        $this->assertEquals($this->endDate, $data['end_date']);
        $this->assertEquals($this->requiredDegreeDays, $data['required_degree_days']);
        $this->assertEquals($this->actualDegreeDays, $data['actual_degree_days']);
        $this->assertStringContainsString(
            $this->pest,
            $data['message']
        );
    }

    #[Test]
    public function it_formats_firebase_message_correctly()
    {
        $firebaseMessage = $this->notification->toFirebase(new \stdClass());

        $this->assertEquals('Pest Degree Day Warning', $firebaseMessage->title);
        $this->assertStringContainsString($this->pest, $firebaseMessage->body);
        $this->assertStringContainsString($this->startDate, $firebaseMessage->body);
        $this->assertStringContainsString($this->endDate, $firebaseMessage->body);
        $this->assertEquals($this->pest, $firebaseMessage->data['pest']);
        $this->assertEquals($this->startDate, $firebaseMessage->data['start_date']);
        $this->assertEquals($this->endDate, $firebaseMessage->data['end_date']);
        $this->assertEquals((string) $this->requiredDegreeDays, $firebaseMessage->data['required_degree_days']);
        $this->assertEquals((string) $this->actualDegreeDays, $firebaseMessage->data['actual_degree_days']);
        $this->assertEquals('pest_degree_day', $firebaseMessage->data['type']);
        $this->assertEquals('high', $firebaseMessage->data['priority']);
    }
}
