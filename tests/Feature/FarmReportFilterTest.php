<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\FarmReport;
use App\Models\Field;
use App\Models\Labour;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FarmReportFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->farm = Farm::factory()->create();

        // Attach user to farm
        $this->farm->users()->attach($this->user->id, [
            'is_owner' => true,
            'role' => 'admin'
        ]);
    }

    #[Test]
    public function it_requires_authentication()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => []
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function it_validates_filters_are_required()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters']);
    }

    #[Test]
    public function it_validates_filters_is_array()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => 'not-an-array'
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters']);
    }

    #[Test]
    public function it_validates_operation_ids_exist()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'operation_ids' => [99999]
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.operation_ids.0']);
    }

    #[Test]
    public function it_validates_labour_ids_exist()
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'labour_ids' => [99999]
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.labour_ids.0']);
    }

    #[Test]
    public function it_validates_date_range_from_is_required_when_date_range_provided()
    {
        $jalaliDate = Jalalian::fromCarbon(now())->format('Y/m/d');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'date_range' => [
                        'to' => $jalaliDate
                    ]
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.date_range.from']);
    }

    #[Test]
    public function it_validates_date_range_to_is_after_or_equal_to_from()
    {
        $fromDate = Jalalian::fromCarbon(now())->format('Y/m/d');
        $toDate = Jalalian::fromCarbon(now()->subDay())->format('Y/m/d');

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'date_range' => [
                        'from' => $fromDate,
                        'to' => $toDate
                    ]
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['filters.date_range.to']);
    }

    #[Test]
    public function it_can_filter_by_reportable_type()
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $plot = \App\Models\Plot::factory()->create(['field_id' => $field->id]);

        // Create reports for different reportable types
        $fieldReport = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $plotReport = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => \App\Models\Plot::class,
            'reportable_id' => $plot->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'reportable_type' => 'Field'
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fieldReport->id);
    }

    #[Test]
    public function it_can_filter_by_reportable_id()
    {
        $field1 = Field::factory()->create(['farm_id' => $this->farm->id]);
        $field2 = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field1->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field2->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'reportable_id' => [$field1->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report1->id);
    }

    #[Test]
    public function it_can_filter_by_multiple_reportable_ids()
    {
        $field1 = Field::factory()->create(['farm_id' => $this->farm->id]);
        $field2 = Field::factory()->create(['farm_id' => $this->farm->id]);
        $field3 = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field1->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field2->id,
            'created_by' => $this->user->id,
        ]);

        $report3 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field3->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'reportable_id' => [$field1->id, $field2->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $report1->id])
            ->assertJsonFragment(['id' => $report2->id])
            ->assertJsonMissing(['id' => $report3->id]);
    }

    #[Test]
    public function it_can_filter_by_operation_ids()
    {
        $operation1 = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $operation2 = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation1->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation2->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'operation_ids' => [$operation1->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report1->id);
    }

    #[Test]
    public function it_can_filter_by_multiple_operation_ids()
    {
        $operation1 = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $operation2 = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $operation3 = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation1->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation2->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report3 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation3->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'operation_ids' => [$operation1->id, $operation2->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $report1->id])
            ->assertJsonFragment(['id' => $report2->id])
            ->assertJsonMissing(['id' => $report3->id]);
    }

    #[Test]
    public function it_can_filter_by_labour_ids()
    {
        $labour1 = Labour::factory()->create();
        $labour2 = Labour::factory()->create();
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour1->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour2->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'labour_ids' => [$labour1->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report1->id);
    }

    #[Test]
    public function it_can_filter_by_multiple_labour_ids()
    {
        $labour1 = Labour::factory()->create();
        $labour2 = Labour::factory()->create();
        $labour3 = Labour::factory()->create();
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour1->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour2->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report3 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'labour_id' => $labour3->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'labour_ids' => [$labour1->id, $labour2->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $report1->id])
            ->assertJsonFragment(['id' => $report2->id])
            ->assertJsonMissing(['id' => $report3->id]);
    }

    #[Test]
    public function it_can_filter_by_date_range()
    {
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);
        $fromDate = now()->subDays(5);
        $toDate = now()->subDays(2);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'date' => now()->subDays(3),
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'date' => now()->subDays(1),
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $report3 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'date' => now()->subDays(6),
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'date_range' => [
                        'from' => Jalalian::fromCarbon($fromDate)->format('Y/m/d'),
                        'to' => Jalalian::fromCarbon($toDate)->format('Y/m/d')
                    ]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report1->id);
    }

    #[Test]
    public function it_can_combine_multiple_filters()
    {
        $operation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $labour = Labour::factory()->create();
        $field1 = Field::factory()->create(['farm_id' => $this->farm->id]);
        $field2 = Field::factory()->create(['farm_id' => $this->farm->id]);
        $fromDate = now()->subDays(5);
        $toDate = now()->subDays(2);

        // Report that matches all filters
        $matchingReport = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation->id,
            'labour_id' => $labour->id,
            'date' => now()->subDays(3),
            'reportable_type' => Field::class,
            'reportable_id' => $field1->id,
            'created_by' => $this->user->id,
        ]);

        // Report with wrong operation
        $wrongOperationReport = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => Operation::factory()->create(['farm_id' => $this->farm->id])->id,
            'labour_id' => $labour->id,
            'date' => now()->subDays(3),
            'reportable_type' => Field::class,
            'reportable_id' => $field1->id,
            'created_by' => $this->user->id,
        ]);

        // Report with wrong date
        $wrongDateReport = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation->id,
            'labour_id' => $labour->id,
            'date' => now()->subDays(10),
            'reportable_type' => Field::class,
            'reportable_id' => $field1->id,
            'created_by' => $this->user->id,
        ]);

        // Report with wrong reportable_id
        $wrongReportableReport = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation->id,
            'labour_id' => $labour->id,
            'date' => now()->subDays(3),
            'reportable_type' => Field::class,
            'reportable_id' => $field2->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'operation_ids' => [$operation->id],
                    'labour_ids' => [$labour->id],
                    'reportable_id' => [$field1->id],
                    'date_range' => [
                        'from' => Jalalian::fromCarbon($fromDate)->format('Y/m/d'),
                        'to' => Jalalian::fromCarbon($toDate)->format('Y/m/d')
                    ]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matchingReport->id)
            ->assertJsonMissing(['id' => $wrongOperationReport->id])
            ->assertJsonMissing(['id' => $wrongDateReport->id])
            ->assertJsonMissing(['id' => $wrongReportableReport->id]);
    }

    #[Test]
    public function it_only_returns_reports_for_the_specified_farm()
    {
        $otherFarm = Farm::factory()->create();
        $field1 = Field::factory()->create(['farm_id' => $this->farm->id]);
        $field2 = Field::factory()->create(['farm_id' => $otherFarm->id]);

        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field1->id,
            'created_by' => $this->user->id,
        ]);

        $report2 = FarmReport::factory()->create([
            'farm_id' => $otherFarm->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field2->id,
            'created_by' => $this->user->id,
        ]);

        // Send filters with a dummy key that won't affect results
        // Empty filters should return all reports for the farm
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    '_dummy' => null // Dummy key to ensure filters array is not empty
                ]
            ]);

        // Since we're filtering by a non-existent type, we should get 0 results
        // But the test is about ensuring only reports for the specified farm are returned
        // So let's use a filter that matches the reportable_type we created
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'reportable_type' => 'Field' // Matches Field reports
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report1->id)
            ->assertJsonMissing(['id' => $report2->id]);
    }

    #[Test]
    public function it_returns_empty_collection_when_no_reports_match_filters()
    {
        $operation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        // Create a report with different operation
        FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => Operation::factory()->create(['farm_id' => $this->farm->id])->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'operation_ids' => [$operation->id]
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function it_includes_operation_labour_and_reportable_relationships()
    {
        $operation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $labour = Labour::factory()->create();
        $field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $operation->id,
            'labour_id' => $labour->id,
            'reportable_type' => Field::class,
            'reportable_id' => $field->id,
            'created_by' => $this->user->id,
        ]);

        // Send filters with reportable_type to ensure filters array is not empty
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
                'filters' => [
                    'reportable_type' => 'Field' // Matches the Field report we created
                ]
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'operation',
                        'labour',
                        'reportable'
                    ]
                ]
            ])
            ->assertJsonPath('data.0.operation.id', $operation->id)
            ->assertJsonPath('data.0.labour.id', $labour->id)
            ->assertJsonPath('data.0.reportable.id', $field->id);
    }
}

