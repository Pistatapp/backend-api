<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Farm;
use App\Models\Field;
use App\Models\Operation;
use App\Models\Labour;
use App\Models\FarmReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class FarmReportTest extends TestCase
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
    public function it_can_list_farm_reports()
    {
        FarmReport::factory()->count(3)->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/farms/{$this->farm->id}/farm_reports");

        $response->assertOk();
    }

    #[Test]
    public function it_can_store_farm_report()
    {
        $data = [
            'date' => '1404/01/12',
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test farm report',
            'value' => 42.5,
            'reportable_type' => 'field',
            'reportable_id' => $this->field->id
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports", $data);

        $response->assertCreated();

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test farm report',
            'value' => 42.5,
            'created_by' => $this->user->id,
            'verified' => false,
        ]);
    }

    #[Test]
    public function it_validates_required_fields_when_storing()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'operation_id', 'labour_id', 'description', 'value', 'reportable_type', 'reportable_id']);
    }

    #[Test]
    public function it_can_show_farm_report()
    {
        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test report details',
            'value' => 42.5,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
        ]);

        $response = $this->getJson("/api/farm_reports/{$report->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'date',
                    'operation',
                    'labour',
                    'description',
                    'value',
                    'reportable',
                    'created_at'
                ]
            ]);
    }

    #[Test]
    public function it_can_update_farm_report()
    {
        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'date' => '2025-04-01',
        ]);

        $data = [
            'date' => '1404/01/12',
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Updated farm report',
            'value' => 55.5,
            'reportable_type' => 'field',
            'reportable_id' => $this->field->id,
            'verified' => true
        ];

        $response = $this->putJson("/api/farm_reports/{$report->id}", $data);

        $response->assertOk();

        $this->assertDatabaseHas('farm_reports', [
            'id' => $report->id,
            'description' => 'Updated farm report',
            'value' => 55.5,
            'verified' => true
        ]);
    }

    #[Test]
    public function it_validates_required_fields_when_updating()
    {
        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->putJson("/api/farm_reports/{$report->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'operation_id', 'labour_id', 'description', 'value', 'reportable_type', 'reportable_id']);
    }

    #[Test]
    public function it_can_delete_farm_report()
    {
        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
        ]);

        $response = $this->deleteJson("/api/farm_reports/{$report->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('farm_reports', ['id' => $report->id]);
    }

    #[Test]
    public function it_can_verify_farm_report()
    {
        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $this->user->id,
            'verified' => false,
        ]);

        $response = $this->patchJson("/api/farm_reports/{$report->id}/verify");

        $response->assertOk();
        $this->assertTrue($report->fresh()->verified);
    }
}
