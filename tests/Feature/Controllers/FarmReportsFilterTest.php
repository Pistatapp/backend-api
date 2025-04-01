<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Operation;
use App\Models\Labour;
use App\Models\FarmReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class FarmReportsFilterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    private $farm;
    private $field;
    private $operation;
    private $labour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();
        $this->user->farms()->attach($this->farm->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);

        $this->field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $this->operation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $this->labour = Labour::factory()->create(['farm_id' => $this->farm->id]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_filter_reports_by_reportable_type_and_id(): void
    {
        // Create reports for a field
        FarmReport::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        // Create another report for a different reportable
        $otherField = Field::factory()->create(['farm_id' => $this->farm->id]);
        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $otherField->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'reportable_type' => 'field',
                'reportable_id' => [$this->field->id]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_can_filter_reports_by_multiple_operations(): void
    {
        // Create reports with specific operation
        FarmReport::factory()->count(2)->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        // Create report with different operation
        $otherOperation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $otherOperation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'operation_ids' => [$this->operation->id, $otherOperation->id]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_can_filter_reports_by_multiple_labours(): void
    {
        // Create reports with specific labour
        FarmReport::factory()->count(2)->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        // Create report with different labour
        $otherLabour = Labour::factory()->create(['farm_id' => $this->farm->id]);
        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $otherLabour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'labour_ids' => [$this->labour->id, $otherLabour->id]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    #[Test]
    public function it_can_filter_reports_by_date_range(): void
    {
        // Create reports for specific dates
        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'date' => '2024-01-01',
        ]);

        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'date' => '2024-01-15',
        ]);

        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'date' => '2024-02-01',
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'date_range' => [
                    'from' => '1402/10/11',
                    'to' => '1402/11/11'
                ]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function it_can_combine_multiple_filters(): void
    {
        // Create base reports
        FarmReport::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'date' => '2024-01-15',
        ]);

        // Create report with different parameters
        $otherOperation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $otherOperation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'date' => '2024-01-15',
        ]);

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'operation_ids' => [$this->operation->id],
                'labour_ids' => [$this->labour->id],
                'date_range' => [
                    'from' => '1402/10/11',
                    'to' => '1402/11/11'
                ]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
