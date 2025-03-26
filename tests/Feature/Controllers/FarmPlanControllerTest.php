<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\Treatment;
use App\Models\Field;
use App\Models\User;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FarmPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;
    protected $treatment;
    protected $field;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user, [
            'is_owner' => true,
            'role' => 'admin'
        ]);
        $this->treatment = Treatment::factory()->create(['farm_id' => $this->farm->id]);
        $this->field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_list_farm_plans()
    {
        FarmPlan::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/farm_plans");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'goal',
                        'status',
                        'created_by'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_can_create_farm_plan()
    {
        $data = [
            'name' => 'Test Plan',
            'goal' => 'Test Goal',
            'referrer' => 'Test Referrer',
            'counselors' => 'Test Counselors',
            'executors' => 'Test Executors',
            'statistical_counselors' => 'Test Statistical Counselors',
            'implementation_location' => 'Test Location',
            'used_materials' => 'Test Materials',
            'evaluation_criteria' => 'Test Criteria',
            'description' => 'Test Description',
            'start_date' => now()->format('Y/m/d'),
            'end_date' => now()->addDays(30)->format('Y/m/d'),
            'details' => [
                [
                    'treatment_id' => $this->treatment->id,
                    'treatables' => [
                        [
                            'treatable_id' => $this->field->id,
                            'treatable_type' => 'field'
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_plans", $data);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'goal',
                    'status',
                    'created_by'
                ]
            ]);

        $this->assertDatabaseHas('farm_plans', [
            'name' => 'Test Plan',
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id,
            'status' => 'pending'
        ]);

        $this->assertDatabaseHas('farm_plan_details', [
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->field->id,
            'treatable_type' => 'App\Models\Field'
        ]);
    }

    #[Test]
    public function it_can_show_farm_plan()
    {
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->getJson("/api/farm_plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'goal',
                    'status',
                    'created_by'
                ]
            ]);
    }

    #[Test]
    public function it_can_update_farm_plan()
    {
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id
        ]);

        $data = [
            'name' => 'Updated Plan',
            'goal' => 'Updated Goal',
            'referrer' => 'Updated Referrer',
            'counselors' => 'Updated Counselors',
            'executors' => 'Updated Executors',
            'statistical_counselors' => 'Updated Statistical Counselors',
            'implementation_location' => 'Updated Location',
            'used_materials' => 'Updated Materials',
            'evaluation_criteria' => 'Updated Criteria',
            'description' => 'Updated Description',
            'start_date' => now()->format('Y/m/d'),
            'end_date' => now()->addDays(30)->format('Y/m/d'),
            'details' => [
                [
                    'treatment_id' => $this->treatment->id,
                    'treatables' => [
                        [
                            'treatable_id' => $this->field->id,
                            'treatable_type' => 'field'
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->putJson("/api/farm_plans/{$plan->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'goal',
                    'status',
                    'created_by'
                ]
            ]);

        $this->assertDatabaseHas('farm_plans', [
            'id' => $plan->id,
            'name' => 'Updated Plan',
            'goal' => 'Updated Goal'
        ]);

        $this->assertDatabaseHas('farm_plan_details', [
            'farm_plan_id' => $plan->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->field->id,
            'treatable_type' => 'App\Models\Field'
        ]);
    }

    #[Test]
    public function it_can_delete_farm_plan()
    {
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'created_by' => $this->user->id
        ]);

        $response = $this->deleteJson("/api/farm_plans/{$plan->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('farm_plans', ['id' => $plan->id]);
    }
}
