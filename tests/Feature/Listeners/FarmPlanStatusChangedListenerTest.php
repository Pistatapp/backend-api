<?php

namespace Tests\Feature\Listeners;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\Field;
use App\Models\Treatment;
use App\Models\FarmPlanDetail;
use App\Events\FarmPlanStatusChanged;
use App\Notifications\FarmPlanStatusNotification;
use App\Listeners\FarmPlanStatusChangedListener;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class FarmPlanStatusChangedListenerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $user;
    private User $farmOwner;
    private Farm $farm;
    private FarmPlan $plan;
    private Field $field1;
    private Field $field2;
    private Treatment $treatment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seeder(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->farmOwner = User::factory()->create();
        $this->farm = Farm::factory()->create();

        // Attach farm owner
        $this->farm->users()->attach($this->farmOwner, [
            'is_owner' => true,
            'role' => 'admin'
        ]);

        $this->farm->users()->attach($this->user, [
            'is_owner' => false,
            'role' => 'operator'
        ]);

        $this->field1 = Field::factory()->create(['farm_id' => $this->farm->id, 'name' => 'Field 1']);
        $this->field2 = Field::factory()->create(['farm_id' => $this->farm->id, 'name' => 'Field 2']);
        $this->treatment = Treatment::factory()->create(['farm_id' => $this->farm->id]);

        $this->plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'name' => 'Test Plan',
            'start_date' => now(),
            'end_date' => now()->addDays(30)
        ]);

        // Create plan details with two fields
        foreach ([$this->field1, $this->field2] as $field) {
            FarmPlanDetail::create([
                'farm_plan_id' => $this->plan->id,
                'treatment_id' => $this->treatment->id,
                'treatable_id' => $field->id,
                'treatable_type' => 'App\Models\Field'
            ]);
        }
    }

    #[Test]
    public function it_sends_notification_when_plan_starts()
    {
        Notification::fake();

        // Manually trigger event listener
        $listener = new FarmPlanStatusChangedListener();
        $event = new FarmPlanStatusChanged($this->plan, 'started');
        $listener->handle($event);

        // Check notification sent to both creator and farm owner
        Notification::assertSentTo(
            [$this->user, $this->farmOwner],
            FarmPlanStatusNotification::class,
            function ($notification) {
                $data = $notification->toArray($this->user);
                return str_contains($data['message'], 'Field 1') &&
                    str_contains($data['message'], 'Field 2') &&
                    str_contains($data['message'], 'Test Plan') &&
                    str_contains($data['message'], 'implementation started');
            }
        );

        Notification::assertSentTo(
            [$this->user, $this->farmOwner],
            FarmPlanStatusNotification::class,
            function ($notification, $channels) {
                // Assert that the notification is sent via the database channel
                return in_array('database', $channels);
            }
        );
    }

    #[Test]
    public function it_sends_notification_when_plan_finishes()
    {
        Notification::fake();

        $this->plan->load(['details.treatable', 'creator', 'farm']);

        // Manually trigger event listener
        $listener = new FarmPlanStatusChangedListener();
        $event = new FarmPlanStatusChanged($this->plan, 'finished');
        $listener->handle($event);

        // Check notification sent to both creator and farm owner
        Notification::assertSentTo(
            [$this->user, $this->farmOwner],
            FarmPlanStatusNotification::class,
            function ($notification) {
                $data = $notification->toArray($this->user);
                return str_contains($data['message'], 'Field 1') &&
                    str_contains($data['message'], 'Field 2') &&
                    str_contains($data['message'], 'Test Plan') &&
                    str_contains($data['message'], 'implementation completed');
            }
        );

        Notification::assertSentTo(
            [$this->user, $this->farmOwner],
            FarmPlanStatusNotification::class,
            function ($notification, $channels) {
                // Assert that the notification is sent via the database channel
                return in_array('database', $channels);
            }
        );
    }

    #[Test]
    public function it_does_not_send_notification_for_other_status_changes()
    {
        Notification::fake();

        // Manually trigger event listener with pending status
        $listener = new FarmPlanStatusChangedListener();
        $event = new FarmPlanStatusChanged($this->plan, 'pending');
        $listener->handle($event);

        Notification::assertNothingSent();
    }
}
