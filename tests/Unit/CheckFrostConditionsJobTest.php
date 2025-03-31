<?php

namespace Tests\Unit;

use App\Jobs\CheckFrostConditionsJob;
use App\Models\Farm;
use App\Models\User;
use App\Models\Warning;
use App\Notifications\FrostNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CheckFrostConditionsJobTest extends TestCase
{
    use RefreshDatabase;

    private Farm $farm;
    private User $user;
    private Warning $warning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->farm = Farm::factory()->create([
            'center' => '35.7219, 51.3347' // Example coordinates
        ]);

        $this->user = User::factory()->create();
        $this->farm->users()->attach($this->user);

        $this->warning = Warning::factory()->create([
            'farm_id' => $this->farm->id,
            'key' => 'frost_warning',
            'enabled' => true,
            'parameters' => ['days' => 3]
        ]);
    }

    #[Test]
    public function it_sends_notification_when_frost_risk_detected()
    {
        Notification::fake();

        // Mock weather API response with frost risk
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [
                            'date' => '2024-01-15',
                            'day' => [
                                'mintemp_c' => -2.5
                            ]
                        ]
                    ]
                ]
            ])
        ]);

        $job = new CheckFrostConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            FrostNotification::class,
            function ($notification) {
                return $notification->temperature === -2.5 &&
                    $notification->days === 3;
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_when_no_frost_risk()
    {
        Notification::fake();

        // Mock weather API response with no frost risk
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [
                            'date' => '2024-01-15',
                            'day' => [
                                'mintemp_c' => 5.0
                            ]
                        ]
                    ]
                ]
            ])
        ]);

        $job = new CheckFrostConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();

        // Disable the warning
        $this->warning->update(['enabled' => false]);

        $job = new CheckFrostConditionsJob();
        $job->handle();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_uses_default_days_parameter_when_not_specified()
    {
        Notification::fake();

        // Remove days parameter
        $this->warning->update(['parameters' => []]);

        // Mock weather API response with frost risk
        Http::fake([
            '*' => Http::response([
                'forecast' => [
                    'forecastday' => [
                        [
                            'date' => '2024-01-15',
                            'day' => [
                                'mintemp_c' => -2.5
                            ]
                        ]
                    ]
                ]
            ])
        ]);

        $job = new CheckFrostConditionsJob();
        $job->handle();

        Notification::assertSentTo(
            $this->user,
            FrostNotification::class,
            function ($notification) {
                return $notification->days === 3; // Default value
            }
        );
    }
}
