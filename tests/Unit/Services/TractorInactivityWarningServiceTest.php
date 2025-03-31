<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Farm;
use App\Models\Warning;
use App\Services\TractorInactivityWarningService;
use App\Notifications\TractorInactivityNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Carbon\Carbon;

class TractorInactivityWarningServiceTest extends TestCase
{
    use RefreshDatabase;

    private TractorInactivityWarningService $service;
    private Farm $farm;
    private User $user;
    private Warning $warning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TractorInactivityWarningService();
        $this->farm = Farm::factory()->create();
        $this->user = User::factory()->create();

        // Set up the farm-user relationship
        $this->farm->users()->attach($this->user->id);

        // Set user's working environment
        $this->user->preferences = ['working_environment' => $this->farm->id];
        $this->user->save();

        // Create warning configuration
        $this->warning = Warning::create([
            'farm_id' => $this->farm->id,
            'key' => 'tractor_inactivity',
            'enabled' => true,
            'parameters' => ['days' => 2],
            'type' => 'condition-based'
        ]);
    }

    #[Test]
    public function it_sends_notification_when_tractor_is_inactive()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Create a tractor with last activity 3 days ago
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => now()->subDays(3)
        ]);

        $this->service->checkAndNotify();

        Notification::assertSentTo(
            [$this->user],
            TractorInactivityNotification::class,
            function ($notification) use ($tractor) {
                return $notification->getTractor()->id === $tractor->id &&
                    $notification->getLastActivity()->eq($tractor->last_activity) &&
                    $notification->getThreshold() === 2 &&
                    $notification->getDate()->eq(today());
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_when_tractor_is_active()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Create a tractor with recent activity
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => now()->subHours(12) // Less than 2 days
        ]);

        $this->service->checkAndNotify();

        Notification::assertNotSentTo([$this->user], TractorInactivityNotification::class);
    }

    #[Test]
    public function it_does_not_send_notification_when_warning_is_disabled()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Disable the warning
        $this->warning->update(['enabled' => false]);

        // Create a tractor with old activity
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => now()->subDays(3)
        ]);

        $this->service->checkAndNotify();

        Notification::assertNotSentTo([$this->user], TractorInactivityNotification::class);
    }

    #[Test]
    public function it_does_not_send_notification_when_user_has_no_working_environment()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Update user to have no working environment
        $this->user->preferences = [];
        $this->user->save();

        // Create a tractor with old activity
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => now()->subDays(3)
        ]);

        $this->service->checkAndNotify();

        Notification::assertNotSentTo([$this->user], TractorInactivityNotification::class);
    }

    #[Test]
    public function it_sends_notification_to_all_farm_users()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Create another user for the same farm
        $anotherUser = User::factory()->create();
        $this->farm->users()->attach($anotherUser->id);

        // Create a tractor with old activity
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => now()->subDays(3)
        ]);

        $this->service->checkAndNotify();

        Notification::assertSentTo(
            [$this->user],
            TractorInactivityNotification::class
        );

        Notification::assertSentTo(
            [$anotherUser],
            TractorInactivityNotification::class
        );
    }

    #[Test]
    public function it_handles_missing_warnings_configuration()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Delete the warning configuration
        $this->warning->delete();

        // Create a tractor with old activity
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => now()->subDays(3)
        ]);

        $this->service->checkAndNotify();

        Notification::assertNotSentTo([$this->user], TractorInactivityNotification::class);
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
    public function it_does_not_send_notification_when_no_last_activity()
    {
        Notification::fake();
        Auth::shouldReceive('user')->andReturn($this->user);

        // Create a tractor with no last activity
        $tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
            'last_activity' => null
        ]);

        $this->service->checkAndNotify();

        Notification::assertNotSentTo([$this->user], TractorInactivityNotification::class);
    }
}
