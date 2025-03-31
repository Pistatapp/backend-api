<?php

namespace Tests\Unit;

use App\Notifications\RadiativeFrostNotification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RadiativeFrostNotificationTest extends TestCase
{
    private float $averageTemp;
    private float $dewPoint;
    private string $date;
    private RadiativeFrostNotification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->averageTemp = 5.2;
        $this->dewPoint = -1.5;
        $this->date = '1402/10/15';

        $this->notification = new RadiativeFrostNotification(
            $this->averageTemp,
            $this->dewPoint,
            $this->date
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

        $this->assertEquals($this->averageTemp, $data['average_temp']);
        $this->assertEquals($this->dewPoint, $data['dew_point']);
        $this->assertEquals($this->date, $data['date']);
        $this->assertStringContainsString(
            $this->date,
            $data['message']
        );
    }

    #[Test]
    public function it_formats_firebase_message_correctly()
    {
        $firebaseMessage = $this->notification->toFirebase(new \stdClass());

        $this->assertEquals('Radiative Frost Warning', $firebaseMessage->title);
        $this->assertStringContainsString($this->date, $firebaseMessage->body);
        $this->assertEquals($this->averageTemp, $firebaseMessage->data['average_temp']);
        $this->assertEquals($this->dewPoint, $firebaseMessage->data['dew_point']);
        $this->assertEquals($this->date, $firebaseMessage->data['date']);
    }
}
