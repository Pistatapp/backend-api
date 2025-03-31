<?php

namespace Tests\Unit;

use App\Jobs\CheckOilSprayConditionsJob;
use App\Models\Farm;
use App\Models\User;
use App\Models\Warning;
use App\Notifications\OilSprayNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CheckOilSprayConditionsJobTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $user;
    private Warning $warning;
    private string $endDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->endDate = '2024-01-15';
        Carbon::setTestNow(Carbon::parse($this->endDate));

        $this->farm = Farm::factory()->create([
            'center' => '35.7219,51.3347' // Tehran coordinates as string
        ]);

        $this->user = User::factory()->create();
        $this->farm->users()->attach($this->user);

        $this->warning = Warning::factory()->create([
            'farm_id' => $this->farm->id,
            'key' => 'oil_spray_warning',
            'enabled' => true,
            'parameters' => [
                'hours' => 100,
                'start_date' => '2024-01-01',
                'end_date' => $this->endDate
            ]
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow(); // Reset time manipulation
    }

    #[Test]
    public function it_sends_notification_when_chilling_hours_are_less_than_required_on_end_date()
    {
        Notification::fake();

        // Mock weather API response for the entire period with insufficient chilling hours
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => array_fill(0, 15, [
                        'hour' => array_fill(0, 24, [
                            'temp_c' => 10.0 // Temperature outside 0-7°C range
                        ])
                    ])
                ]
            ])
        ]);

        $job = new CheckOilSprayConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            OilSprayNotification::class,
            function ($notification) {
                return $notification->requiredHours === 100 &&
                       $notification->actualHours === 0;
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_before_end_date()
    {
        Notification::fake();

        // Set current date to one day before end date
        Carbon::setTestNow(Carbon::parse($this->endDate)->subDay());

        // Mock weather API response (shouldn't be called)
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => array_fill(0, 15, [
                        'hour' => array_fill(0, 24, [
                            'temp_c' => 10.0
                        ])
                    ])
                ]
            ])
        ]);

        $job = new CheckOilSprayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_chilling_hours_are_sufficient()
    {
        Notification::fake();

        // Mock weather API response with sufficient chilling hours
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => array_fill(0, 15, [
                        'hour' => array_fill(0, 24, [
                            'temp_c' => 5.0 // Temperature within 0-7°C range
                        ])
                    ])
                ]
            ])
        ]);

        $job = new CheckOilSprayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();

        // Disable the warning
        $this->warning->update(['enabled' => false]);

        $job = new CheckOilSprayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_parameters_are_missing()
    {
        Notification::fake();

        // Remove required parameters
        $this->warning->update(['parameters' => []]);

        $job = new CheckOilSprayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }
}
