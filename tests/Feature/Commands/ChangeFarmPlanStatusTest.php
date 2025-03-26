<?php

namespace Tests\Feature\Commands;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\Field;
use App\Models\Treatment;
use App\Models\FarmPlanDetail;
use App\Events\FarmPlanStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

class ChangeFarmPlanStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Field $field1;
    private Field $field2;
    private Treatment $treatment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->field1 = Field::factory()->create(['farm_id' => $this->farm->id, 'name' => 'Field 1']);
        $this->field2 = Field::factory()->create(['farm_id' => $this->farm->id, 'name' => 'Field 2']);
        $this->treatment = Treatment::factory()->create(['farm_id' => $this->farm->id]);
    }

    #[Test]
    public function it_changes_plan_status_to_started_and_dispatches_event()
    {
        Event::fake([FarmPlanStatusChanged::class]);
        Notification::fake();

        // Create a plan that should start today
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'name' => 'Test Plan',
            'start_date' => now()->subHour(), // Started an hour ago
            'end_date' => now()->addDays(30),
            'status' => 'pending'
        ]);

        // Create plan details
        foreach ([$this->field1, $this->field2] as $field) {
            FarmPlanDetail::create([
                'farm_plan_id' => $plan->id,
                'treatment_id' => $this->treatment->id,
                'treatable_id' => $field->id,
                'treatable_type' => 'App\Models\Field'
            ]);
        }

        // Run the command
        $this->artisan('app:change-farm-plan-status');

        // Assert plan status was changed
        $this->assertEquals('started', $plan->fresh()->status);

        // Assert event was dispatched
        Event::assertDispatched(FarmPlanStatusChanged::class, function ($event) use ($plan) {
            return $event->plan->id === $plan->id && $event->status === 'started';
        });
    }

    #[Test]
    public function it_changes_plan_status_to_finished_and_dispatches_event()
    {
        Event::fake([FarmPlanStatusChanged::class]);
        Notification::fake();

        // Create a plan that has ended
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'name' => 'Test Plan',
            'start_date' => now()->subDays(30),
            'end_date' => now()->subHour(), // Ended an hour ago
            'status' => 'started'
        ]);

        // Create plan details
        foreach ([$this->field1, $this->field2] as $field) {
            FarmPlanDetail::create([
                'farm_plan_id' => $plan->id,
                'treatment_id' => $this->treatment->id,
                'treatable_id' => $field->id,
                'treatable_type' => 'App\Models\Field'
            ]);
        }

        // Run the command
        $this->artisan('app:change-farm-plan-status');

        // Assert plan status was changed
        $this->assertEquals('finished', $plan->fresh()->status);

        // Assert event was dispatched
        Event::assertDispatched(FarmPlanStatusChanged::class, function ($event) use ($plan) {
            return $event->plan->id === $plan->id && $event->status === 'finished';
        });
    }

    #[Test]
    public function it_does_not_change_status_or_dispatch_event_when_no_change_needed()
    {
        Event::fake([FarmPlanStatusChanged::class]);
        Notification::fake();

        // Create a plan that is currently active
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'name' => 'Test Plan',
            'start_date' => now()->subDays(15),
            'end_date' => now()->addDays(15),
            'status' => 'started'
        ]);

        // Run the command
        $this->artisan('app:change-farm-plan-status');

        // Assert plan status wasn't changed
        $this->assertEquals('started', $plan->fresh()->status);

        // Assert no event was dispatched
        Event::assertNotDispatched(FarmPlanStatusChanged::class);
    }

    #[Test]
    public function it_handles_multiple_plans_correctly()
    {
        Event::fake([FarmPlanStatusChanged::class]);
        Notification::fake();

        // Create three plans with different states
        $pendingPlan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'start_date' => now()->subHour(),
            'end_date' => now()->addDays(30),
            'status' => 'pending'
        ]);

        $activePlan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'start_date' => now()->subDays(15),
            'end_date' => now()->addDays(15),
            'status' => 'started'
        ]);

        $finishedPlan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'start_date' => now()->subDays(30),
            'end_date' => now()->subHour(),
            'status' => 'started'
        ]);

        // Run the command
        $this->artisan('app:change-farm-plan-status');

        // Assert correct status changes
        $this->assertEquals('started', $pendingPlan->fresh()->status);
        $this->assertEquals('started', $activePlan->fresh()->status);
        $this->assertEquals('finished', $finishedPlan->fresh()->status);

        // Assert events were dispatched for changed plans only
        Event::assertDispatched(FarmPlanStatusChanged::class, function ($event) use ($pendingPlan) {
            return $event->plan->id === $pendingPlan->id && $event->status === 'started';
        });

        Event::assertDispatched(FarmPlanStatusChanged::class, function ($event) use ($finishedPlan) {
            return $event->plan->id === $finishedPlan->id && $event->status === 'finished';
        });

        Event::assertDispatchedTimes(FarmPlanStatusChanged::class, 2);
    }
}
