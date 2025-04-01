<?php

namespace Tests\Unit;

use App\Notifications\CropTypeDegreeDayNotification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CropTypeDegreeDayNotificationTest extends TestCase
{
    private string $cropType;
    private string $startDate;
    private string $endDate;
    private float $requiredDegreeDays;
    private float $actualDegreeDays;
    private CropTypeDegreeDayNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cropType = 'apple';
        $this->startDate = '1402/10/01';
        $this->endDate = '1402/10/15';
        $this->requiredDegreeDays = 100.0;
        $this->actualDegreeDays = 75.0;

        $this->notification = new CropTypeDegreeDayNotification(
            $this->cropType,
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

        $this->assertContains('database', $channels);
        $this->assertContains('firebase', $channels);
    }

    #[Test]
    public function it_formats_database_notification_correctly()
    {
        $notificationArray = $this->notification->toArray(new \stdClass());

        $this->assertArrayHasKey('message', $notificationArray);
        $this->assertArrayHasKey('crop_type', $notificationArray);
        $this->assertArrayHasKey('start_date', $notificationArray);
        $this->assertArrayHasKey('end_date', $notificationArray);
        $this->assertArrayHasKey('required_degree_days', $notificationArray);
        $this->assertArrayHasKey('actual_degree_days', $notificationArray);

        $this->assertEquals($this->cropType, $notificationArray['crop_type']);
        $this->assertEquals($this->startDate, $notificationArray['start_date']);
        $this->assertEquals($this->endDate, $notificationArray['end_date']);
        $this->assertEquals($this->requiredDegreeDays, $notificationArray['required_degree_days']);
        $this->assertEquals($this->actualDegreeDays, $notificationArray['actual_degree_days']);
    }

    #[Test]
    public function it_formats_firebase_message_correctly()
    {
        $firebaseMessage = $this->notification->toFirebase(new \stdClass());

        $this->assertEquals('Crop Type Degree Day Warning', $firebaseMessage->title);
        $this->assertStringContainsString($this->cropType, $firebaseMessage->body);
        $this->assertStringContainsString($this->startDate, $firebaseMessage->body);
        $this->assertStringContainsString($this->endDate, $firebaseMessage->body);
        $this->assertEquals($this->cropType, $firebaseMessage->data['crop_type']);
        $this->assertEquals($this->startDate, $firebaseMessage->data['start_date']);
        $this->assertEquals($this->endDate, $firebaseMessage->data['end_date']);
        $this->assertEquals((string) $this->requiredDegreeDays, $firebaseMessage->data['required_degree_days']);
        $this->assertEquals((string) $this->actualDegreeDays, $firebaseMessage->data['actual_degree_days']);
        $this->assertEquals('crop_type_degree_day', $firebaseMessage->data['type']);
        $this->assertEquals('high', $firebaseMessage->data['priority']);
    }
}
