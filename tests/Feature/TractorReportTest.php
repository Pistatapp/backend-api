<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Operation;
use App\Models\Field;
use App\Models\Tractor;
use App\Models\TractorReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RolePermissionSeeder;
use PHPUnit\Framework\Attributes\Test;

class TractorReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Farm $farm;
    protected Tractor $tractor;
    protected Operation $operation;
    protected Field $field;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();
        $this->user->farms()->attach($this->farm->id, [
            'role' => 'admin',
            'is_owner' => true,
        ]);

        $this->tractor = Tractor::factory()->create(['farm_id' => $this->farm->id]);
        $this->operation = Operation::factory()->create(['farm_id' => $this->farm->id]);
        $this->field = Field::factory()->create(['farm_id' => $this->farm->id]);

        $this->actingAs($this->user);
    }

    #[Test]
    public function it_can_store_tractor_report()
    {
        $data = [
            'date' => '1404/01/16',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'description' => 'Test tractor report description'
        ];

        $response = $this->postJson("/api/tractors/{$this->tractor->id}/tractor_reports", $data);

        $response->assertCreated();

        $this->assertDatabaseHas('tractor_reports', [
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'description' => 'Test tractor report description',
        ]);
    }

    #[Test]
    public function it_prevents_duplicate_reports_for_same_time_period()
    {
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => jalali_to_carbon('1404/01/01'),
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $data = [
            'date' => '1404/01/01',
            'start_time' => '08:00',
            'end_time' => '16:00',
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'description' => 'Test duplicate report'
        ];

        $response = $this->postJson("/api/tractors/{$this->tractor->id}/tractor_reports", $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    #[Test]
    public function it_can_list_tractor_reports()
    {
        TractorReport::factory()->count(3)->create([
            'tractor_id' => $this->tractor->id
        ]);

        $response = $this->getJson("/api/tractors/{$this->tractor->id}/tractor_reports");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'tractor_id',
                        'operation',
                        'field',
                        'date',
                        'start_time',
                        'end_time',
                        'description',
                        'created_by'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_can_show_tractor_report()
    {
        $report = TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'description' => 'Test report',
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/tractor_reports/{$report->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'tractor_id',
                    'operation',
                    'field',
                    'date',
                    'start_time',
                    'end_time',
                    'description',
                    'created_by'
                ]
            ]);
    }

    #[Test]
    public function it_can_update_tractor_report()
    {
        $report = TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        $data = [
            'date' => '1404/01/02',
            'start_time' => '09:00',
            'end_time' => '17:00',
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'description' => 'Updated description'
        ];

        $response = $this->putJson("/api/tractor_reports/{$report->id}", $data);

        $response->assertOk();

        $this->assertDatabaseHas('tractor_reports', [
            'id' => $report->id,
            'description' => 'Updated description'
        ]);
    }

    #[Test]
    public function it_can_delete_tractor_report()
    {
        $report = TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/tractor_reports/{$report->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('tractor_reports', ['id' => $report->id]);
    }

    #[Test]
    public function it_can_filter_reports_by_date_range()
    {
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => jalali_to_carbon('1403/12/29'),
        ]);
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => jalali_to_carbon('1404/01/01'),
        ]);
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => jalali_to_carbon('1404/01/15'),
        ]);
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'date' => jalali_to_carbon('1404/02/01'),
        ]);

        $response = $this->postJson("/api/tractor_reports/filter", [
            'tractor_id' => $this->tractor->id,
            'from_date' => '1404/01/01',
            'to_date' => '1404/01/30'
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.date', '1404/01/01')
            ->assertJsonPath('data.1.date', '1404/01/15');
    }

    #[Test]
    public function it_can_filter_reports_by_operation()
    {
        $operation2 = Operation::factory()->create(['farm_id' => $this->farm->id]);

        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id,
        ]);
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $operation2->id,
        ]);

        $response = $this->postJson("/api/tractor_reports/filter", [
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.operation.id', $this->operation->id);
    }

    #[Test]
    public function it_can_filter_reports_by_field()
    {
        $field2 = Field::factory()->create(['farm_id' => $this->farm->id]);

        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'field_id' => $this->field->id,
        ]);
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'field_id' => $field2->id,
        ]);

        $response = $this->postJson("/api/tractor_reports/filter", [
            'tractor_id' => $this->tractor->id,
            'field_id' => $this->field->id
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.field.id', $this->field->id);
    }

    #[Test]
    public function it_can_combine_multiple_filters()
    {
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'date' => jalali_to_carbon('1404/01/01'),
        ]);
        TractorReport::factory()->create([
            'tractor_id' => $this->tractor->id,
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
            'date' => jalali_to_carbon('1404/02/01'),
        ]);

        $response = $this->postJson("/api/tractor_reports/filter", [
            'tractor_id' => $this->tractor->id,
            'from_date' => '1404/01/01',
            'to_date' => '1404/01/30',
            'operation_id' => $this->operation->id,
            'field_id' => $this->field->id,
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.date', '1404/01/01')
            ->assertJsonPath('data.0.operation.id', $this->operation->id)
            ->assertJsonPath('data.0.field.id', $this->field->id);
    }
}
