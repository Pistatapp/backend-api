<?php

namespace Tests\Unit;

use App\Jobs\CheckCropTypeDegreeDayConditionsJob;
use App\Models\Farm;
use App\Models\User;
use App\Models\Warning;
use App\Notifications\CropTypeDegreeDayNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckCropTypeDegreeDayConditionsJobTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $user;
    private Warning $warning;
    private string $endDate = '2024-01-15';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse($this->endDate));

        $this->farm = Farm::factory()->create();
        $this->user = User::factory()->create();
        $this->farm->users()->attach($this->user);

        $this->warning = Warning::factory()->create([
            'farm_id' => $this->farm->id,
            'key' => 'crop_type_degree_day_warning',
            'enabled' => true,
            'parameters' => [
                'degree_days' => 100.0,
                'start_date' => '2024-01-01',
                'end_date' => $this->endDate,
                'crop_type' => 'apple'
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

        $job = new CheckCropTypeDegreeDayConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            CropTypeDegreeDayNotification::class,
            function ($notification) {
                return $notification->cropType === 'apple' &&
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

        $job = new CheckCropTypeDegreeDayConditionsJob();
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

        $job = new CheckCropTypeDegreeDayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();

        $this->warning->update(['enabled' => false]);

        $job = new CheckCropTypeDegreeDayConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_parameters_are_missing()
    {
        Notification::fake();

        $this->warning->update(['parameters' => []]);

        $job = new CheckCropTypeDegreeDayConditionsJob();
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

        $job = new CheckCropTypeDegreeDayConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            CropTypeDegreeDayNotification::class,
            function ($notification) {
                return $notification->actualDegreeDays === 0.0; // Should be 0 since temp is below base
            }
        );
    }
}
