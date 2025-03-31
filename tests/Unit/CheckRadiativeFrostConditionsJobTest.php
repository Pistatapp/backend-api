<?php

namespace Tests\Unit;

use App\Jobs\CheckRadiativeFrostConditionsJob;
use App\Models\Farm;
use App\Models\User;
use App\Models\Warning;
use App\Notifications\RadiativeFrostNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CheckRadiativeFrostConditionsJobTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $user;
    private Warning $warning;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a farm with a center as a string instead of an array
        $this->farm = Farm::factory()->create([
            'center' => '35.7219,51.3347' // Example coordinates as a string
        ]);

        $this->user = User::factory()->create();
        $this->farm->users()->attach($this->user);

        $this->warning = Warning::factory()->create([
            'farm_id' => $this->farm->id,
            'key' => 'radiative_frost_warning',
            'enabled' => true
        ]);
    }

    #[Test]
    public function it_sends_notification_when_radiative_frost_risk_detected()
    {
        Notification::fake();

        // Mock weather API response with radiative frost risk conditions
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [], // Today (index 0)
                        [   // Tomorrow (index 1)
                            'date' => '2024-01-15',
                            'day' => [
                                'avgtemp_c' => 2.0,
                            ],
                            'hour' => array_fill(0, 24, [
                                'dewpoint_c' => -10.0, // High dew point difference
                            ])
                        ]
                    ]
                ]
            ])
        ]);

        $job = new CheckRadiativeFrostConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            RadiativeFrostNotification::class,
            function ($notification) {
                return $notification->averageTemp === 2.0 &&
                       $notification->dewPoint === -10.0;
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_when_no_radiative_frost_risk()
    {
        Notification::fake();

        // Mock weather API response with no frost risk
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [], // Today (index 0)
                        [   // Tomorrow (index 1)
                            'date' => '2024-01-15',
                            'day' => [
                                'avgtemp_c' => 15.0,
                            ],
                            'hour' => array_fill(0, 24, [
                                'dewpoint_c' => 12.0, // Small dew point difference
                            ])
                        ]
                    ]
                ]
            ])
        ]);

        $job = new CheckRadiativeFrostConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();

        // Disable the warning
        $this->warning->update(['enabled' => false]);

        $job = new CheckRadiativeFrostConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }
}
