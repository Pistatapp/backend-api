<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\FarmPlan;
use App\Models\FarmPlanDetail;
use App\Models\Field;
use App\Models\Row;
use App\Models\Tree;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FarmPlanFilterTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $farm;
    protected $field;
    protected $row;
    protected $tree;
    protected $treatment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();
        $this->field = Field::factory()->create(['farm_id' => $this->farm->id, 'name' => 'Test Field']);
        $this->row = Row::factory()->create(['field_id' => $this->field->id, 'name' => 'Test Row']);
        $this->tree = Tree::factory()->create(['row_id' => $this->row->id, 'name' => 'Test Tree']);
        $this->treatment = Treatment::factory()->create(['farm_id' => $this->farm->id]);
    }

    #[Test]
    public function it_can_filter_farm_plans_by_date_range()
    {
        // Create farm plans with different dates
        $plan1 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Plan 1',
            'start_date' => '2023-01-01',
            'end_date' => '2023-01-31'
        ]);

        $plan2 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Plan 2',
            'start_date' => '2023-01-15',
            'end_date' => '2023-01-20'
        ]);

        $plan3 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Plan 3',
            'start_date' => '2023-03-01',
            'end_date' => '2023-03-31'
        ]);

        // Filter by date range (Jalali dates)
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", [
                'from_date' => '1401/10/11', // 2023-01-01 in Jalali
                'to_date' => '1401/11/11'    // 2023-02-01 in Jalali
            ]);

        $response->assertStatus(200);

        $planNames = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Plan 1', $planNames);
        $this->assertContains('Plan 2', $planNames);
        $this->assertNotContains('Plan 3', $planNames);
    }

    #[Test]
    public function it_can_filter_farm_plans_by_single_treatable()
    {
        // Create farm plans with different treatables
        $plan1 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Field Plan'
        ]);

        $plan2 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Row Plan'
        ]);

        // Add treatables to plans
        FarmPlanDetail::create([
            'farm_plan_id' => $plan1->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->field->id,
            'treatable_type' => 'App\Models\Field'
        ]);

        FarmPlanDetail::create([
            'farm_plan_id' => $plan2->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->row->id,
            'treatable_type' => 'App\Models\Row'
        ]);

        // Filter by field treatable
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", [
                'treatable' => [
                    [
                        'treatable_id' => $this->field->id,
                        'treatable_type' => 'field'
                    ]
                ]
            ]);

        $response->assertStatus(200);

        $planNames = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Field Plan', $planNames);
        $this->assertNotContains('Row Plan', $planNames);
    }

    #[Test]
    public function it_can_filter_farm_plans_by_multiple_treatables()
    {
        // Create farm plans
        $plan1 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Multi Treatable Plan'
        ]);

        $plan2 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Single Treatable Plan'
        ]);

        // Add treatables to plans
        FarmPlanDetail::create([
            'farm_plan_id' => $plan1->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->field->id,
            'treatable_type' => 'App\Models\Field'
        ]);

        FarmPlanDetail::create([
            'farm_plan_id' => $plan1->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->row->id,
            'treatable_type' => 'App\Models\Row'
        ]);

        FarmPlanDetail::create([
            'farm_plan_id' => $plan2->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->tree->id,
            'treatable_type' => 'App\Models\Tree'
        ]);

        // Filter by multiple treatables
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", [
                'treatable' => [
                    [
                        'treatable_id' => $this->field->id,
                        'treatable_type' => 'field'
                    ],
                    [
                        'treatable_id' => $this->row->id,
                        'treatable_type' => 'row'
                    ]
                ]
            ]);

        $response->assertStatus(200);

        $planNames = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Multi Treatable Plan', $planNames);
        $this->assertNotContains('Single Treatable Plan', $planNames);
    }

    #[Test]
    public function it_can_filter_farm_plans_by_date_and_treatable_combined()
    {
        // Create farm plans
        $plan1 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Matching Plan',
            'start_date' => '2023-01-15',
            'end_date' => '2023-01-20'
        ]);

        $plan2 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Wrong Date Plan',
            'start_date' => '2023-03-01',
            'end_date' => '2023-03-31'
        ]);

        $plan3 = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Wrong Treatable Plan',
            'start_date' => '2023-01-15',
            'end_date' => '2023-01-20'
        ]);

        // Add treatables
        FarmPlanDetail::create([
            'farm_plan_id' => $plan1->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->field->id,
            'treatable_type' => 'App\Models\Field'
        ]);

        FarmPlanDetail::create([
            'farm_plan_id' => $plan3->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->tree->id,
            'treatable_type' => 'App\Models\Tree'
        ]);

        // Filter by both date and treatable
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", [
                'from_date' => '1401/10/25', // 2023-01-15 in Jalali
                'to_date' => '1401/11/01',   // 2023-01-20 in Jalali
                'treatable' => [
                    [
                        'treatable_id' => $this->field->id,
                        'treatable_type' => 'field'
                    ]
                ]
            ]);

        $response->assertStatus(200);

        $planNames = collect($response->json('data'))->pluck('name')->toArray();
        $this->assertContains('Matching Plan', $planNames);
        $this->assertNotContains('Wrong Date Plan', $planNames);
        $this->assertNotContains('Wrong Treatable Plan', $planNames);
    }

    #[Test]
    public function it_returns_correct_response_structure()
    {
        $plan = FarmPlan::factory()->create([
            'farm_id' => $this->farm->id,
            'name' => 'Test Plan',
            'start_date' => '2023-01-01',
            'end_date' => '2023-01-31'
        ]);

        FarmPlanDetail::create([
            'farm_plan_id' => $plan->id,
            'treatment_id' => $this->treatment->id,
            'treatable_id' => $this->field->id,
            'treatable_type' => 'App\Models\Field'
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", []);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'from_date',
                    'to_date',
                    'name',
                    'treatables' => [
                        '*' => [
                            'name',
                            'type'
                        ]
                    ]
                ]
            ]
        ]);

        $data = $response->json('data')[0];
        $this->assertEquals('1401/10/11', $data['from_date']); // Jalali format
        $this->assertEquals('1401/11/11', $data['to_date']);   // Jalali format
        $this->assertEquals('Test Plan', $data['name']);
        $this->assertIsArray($data['treatables']);
    }

    #[Test]
    public function it_validates_request_parameters()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", [
                'treatable' => [
                    [
                        'treatable_id' => 'invalid',
                        'treatable_type' => 'invalid_type'
                    ]
                ]
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['treatable.0.treatable_id', 'treatable.0.treatable_type']);
    }

    #[Test]
    public function it_returns_empty_result_when_no_plans_match()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/farm_plans/filter", [
                'from_date' => '1400/01/01',
                'to_date' => '1400/01/31'
            ]);

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }
}
