<?php

namespace Tests\Unit;

use App\Jobs\CheckPestDegreeDayConditionsJob;
use App\Models\Farm;
use App\Models\User;
use App\Models\Warning;
use App\Notifications\PestDegreeDayNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CheckPestDegreeDayConditionsJobTest extends TestCase
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
            'center' => '35.7219,51.3347'
        ]);

        $this->user = User::factory()->create();
        $this->farm->users()->attach($this->user);

        $this->warning = Warning::factory()->create([
            'farm_id' => $this->farm->id,
            'key' => 'pest_degree_day_warning',
            'enabled' => true,
            'parameters' => [
                'degree_days' => 100.0,
                'start_date' => '2024-01-01',
                'end_date' => $this->endDate,
                'pest' => 'codling_moth'
            ]
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow();
    }

    #[Test]
    public function it_sends_notification_when_degree_days_are_less_than_required_on_end_date()
    {
        Notification::fake();

        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => array_fill(0, 15, [
                        'day' => [
                            'avgtemp_c' => 15.0 // This will result in 5 degree days per day (15-10)
                        ]
                    ])
                ]
            ])
        ]);

        $job = new CheckPestDegreeDayConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            PestDegreeDayNotification::class,
            function ($notification) {
                return $notification->pest === 'codling_moth' &&
                       $notification->requiredDegreeDays === 100.0 &&
                       $notification->actualDegreeDays === 75.0; // 15 days * 5 degree days
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_before_end_date()
    {
        Notification::fake();

        Carbon::setTestNow(Carbon::parse($this->endDate)->subDay());

        $job = new CheckPestDegreeDayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_degree_days_are_sufficient()
    {
        Notification::fake();

        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => array_fill(0, 15, [
                        'day' => [
                            'avgtemp_c' => 25.0 // This will result in 15 degree days per day (25-10)
                        ]
                    ])
                ]
            ])
        ]);

        $job = new CheckPestDegreeDayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();

        $this->warning->update(['enabled' => false]);

        $job = new CheckPestDegreeDayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_parameters_are_missing()
    {
        Notification::fake();

        $this->warning->update(['parameters' => []]);

        $job = new CheckPestDegreeDayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_handles_temperatures_below_base_correctly()
    {
        Notification::fake();

        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => array_fill(0, 15, [
                        'day' => [
                            'avgtemp_c' => 5.0 // Below base temperature of 10°C
                        ]
                    ])
                ]
            ])
        ]);

        $job = new CheckPestDegreeDayConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            PestDegreeDayNotification::class,
            function ($notification) {
                return $notification->actualDegreeDays === 0.0; // Should be 0 since temp is below base
            }
        );
    }
}
