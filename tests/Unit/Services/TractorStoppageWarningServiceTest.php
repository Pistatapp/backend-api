<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Farm;
use App\Models\GpsDailyReport;
use App\Models\Warning;
use App\Services\TractorStoppageWarningService;
use App\Notifications\TractorStoppageNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class TractorStoppageWarningServiceTest extends TestCase
{
    use RefreshDatabase;

    private TractorStoppageWarningService $service;
    private Farm $farm;
    private User $user;
    private Tractor $tractor;
    private GpsDailyReport $dailyReport;
    private Warning $warning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TractorStoppageWarningService();
        $this->farm = Farm::factory()->create();
        $this->user = User::factory()->create();
        $this->tractor = Tractor::factory()->create(['farm_id' => $this->farm->id]);
        $this->dailyReport = GpsDailyReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => today(),
            'stoppage_duration' => 7200 // 2 hours in seconds
        ]);

        // Set up the farm-user relationship
        $this->farm->users()->attach($this->user->id);

        // Set user's working environment
        $this->user->preferences = ['working_environment' => $this->farm->id];
        $this->user->save();

        // Create warning configuration
        $this->warning = Warning::create([
            'farm_id' => $this->farm->id,
            'key' => 'tractor_stoppage',
            'enabled' => true,
            'parameters' => ['hours' => 1],
            'type' => 'condition-based'
        ]);
    }

    #[Test]
    public function it_sends_notification_when_stoppage_exceeds_threshold()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        $this->service->checkAndNotify();

        Notification::assertSentTo(
            $this->user,
            TractorStoppageNotification::class,
            function ($notification) {
                return $notification->getStoppageDuration() === 7200 &&
                    $notification->getThreshold() === 1;
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Disable the warning
        $this->warning->update(['enabled' => false]);

        $this->service->checkAndNotify();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_stoppage_is_below_threshold()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Update daily report with stoppage duration below threshold
        $this->dailyReport->update([
            'stoppage_duration' => 1800 // 30 minutes in seconds
        ]);

        $this->service->checkAndNotify();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_sends_notification_to_all_farm_users()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Create another user for the same farm
        $anotherUser = User::factory()->create();
        $this->farm->users()->attach($anotherUser->id);

        $this->service->checkAndNotify();

        Notification::assertSentTo(
            $this->user,
            TractorStoppageNotification::class
        );

        Notification::assertSentTo(
            $anotherUser,
            TractorStoppageNotification::class
        );
    }

    #[Test]
    public function it_handles_missing_warnings_configuration()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Delete the warning configuration
        $this->warning->delete();

        $this->service->checkAndNotify();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_no_user_is_logged_in()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn(null);

        $this->service->checkAndNotify();

        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_notification_when_user_has_no_working_environment()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Remove working environment from user preferences
        $this->user->preferences = [];
        $this->user->save();

        $this->service->checkAndNotify();

        Notification::assertNothingSent();
    }
}
