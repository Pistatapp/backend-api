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
    public function it_can_store_farm_report_with_multiple_reportables()
    {
        $data = [
            'date' => '1404/01/12',
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test farm report',
            'value' => 42.5,
            'reportables' => [
                ['type' => 'field', 'id' => $this->field->id],
                ['type' => 'farm', 'id' => $this->farm->id]
            ]
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports", $data);

        $response->assertCreated();

        // Check that reports were created for both reportables
        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test farm report',
            'value' => 42.5,
            'created_by' => $this->user->id,
            'verified' => false,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
        ]);

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test farm report',
            'value' => 42.5,
            'created_by' => $this->user->id,
            'verified' => false,
            'reportable_type' => 'App\\Models\\Farm',
            'reportable_id' => $this->farm->id,
        ]);
    }

    #[Test]
    public function it_can_store_farm_report_with_hierarchical_sub_items()
    {
        // Create some test data for hierarchical testing
        $plot = \App\Models\Plot::factory()->create(['field_id' => $this->field->id]);
        $row = \App\Models\Row::factory()->create(['field_id' => $this->field->id]);
        $tree = \App\Models\Tree::factory()->create(['row_id' => $row->id]);

        $data = [
            'date' => '1404/01/12',
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Test hierarchical report',
            'value' => 100.0,
            'reportables' => [
                ['type' => 'field', 'id' => $this->field->id]
            ],
            'include_sub_items' => true
        ];

        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports", $data);

        $response->assertCreated();

        // Check that reports were created for the field and all its sub-items
        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Test hierarchical report',
            'value' => 100.0,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
        ]);

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Test hierarchical report',
            'value' => 100.0,
            'reportable_type' => 'App\\Models\\Plot',
            'reportable_id' => $plot->id,
        ]);

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Test hierarchical report',
            'value' => 100.0,
            'reportable_type' => 'App\\Models\\Row',
            'reportable_id' => $row->id,
        ]);

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Test hierarchical report',
            'value' => 100.0,
            'reportable_type' => 'App\\Models\\Tree',
            'reportable_id' => $tree->id,
        ]);
    }

    #[Test]
    public function it_validates_required_fields_when_storing()
    {
        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'operation_id', 'labour_id', 'description', 'value', 'reportables']);
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
    public function it_can_update_farm_report_with_multiple_reportables()
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
            'reportables' => [
                ['type' => 'field', 'id' => $this->field->id],
                ['type' => 'farm', 'id' => $this->farm->id]
            ],
            'verified' => true
        ];

        $response = $this->putJson("/api/farm_reports/{$report->id}", $data);

        $response->assertOk();

        // Check that the original report was updated
        $this->assertDatabaseHas('farm_reports', [
            'id' => $report->id,
            'description' => 'Updated farm report',
            'value' => 55.5,
            'verified' => true
        ]);

        // Check that a new report was created for the farm
        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Updated farm report',
            'value' => 55.5,
            'verified' => false, // New reports are not verified by default
            'reportable_type' => 'App\\Models\\Farm',
            'reportable_id' => $this->farm->id,
        ]);
    }

    #[Test]
    public function it_can_update_farm_report_with_hierarchical_sub_items()
    {
        // Create some test data for hierarchical testing
        $plot = \App\Models\Plot::factory()->create(['field_id' => $this->field->id]);
        $row = \App\Models\Row::factory()->create(['field_id' => $this->field->id]);
        $tree = \App\Models\Tree::factory()->create(['row_id' => $row->id]);

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
            'description' => 'Updated hierarchical report',
            'value' => 200.0,
            'reportables' => [
                ['type' => 'field', 'id' => $this->field->id]
            ],
            'include_sub_items' => true,
            'verified' => true
        ];

        $response = $this->putJson("/api/farm_reports/{$report->id}", $data);

        $response->assertOk();

        // Check that the original report was updated
        $this->assertDatabaseHas('farm_reports', [
            'id' => $report->id,
            'description' => 'Updated hierarchical report',
            'value' => 200.0,
            'verified' => true
        ]);

        // Check that reports were created/updated for all sub-items
        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Updated hierarchical report',
            'value' => 200.0,
            'verified' => false, // New reports are not verified by default
            'reportable_type' => 'App\\Models\\Plot',
            'reportable_id' => $plot->id,
        ]);

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Updated hierarchical report',
            'value' => 200.0,
            'verified' => false, // New reports are not verified by default
            'reportable_type' => 'App\\Models\\Row',
            'reportable_id' => $row->id,
        ]);

        $this->assertDatabaseHas('farm_reports', [
            'farm_id' => $this->farm->id,
            'description' => 'Updated hierarchical report',
            'value' => 200.0,
            'verified' => false, // New reports are not verified by default
            'reportable_type' => 'App\\Models\\Tree',
            'reportable_id' => $tree->id,
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
            ->assertJsonValidationErrors(['date', 'operation_id', 'labour_id', 'description', 'value', 'reportables']);
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

        #[Test]
    public function only_report_creator_or_farm_admins_can_update_report()
    {
                // Create the report creator (different from the test user)
        /** @var User $creator */
        $creator = User::factory()->create();
        $creator->assignRole('operator');

        // Create a farm admin (different from creator and test user)
        /** @var User $farmAdmin */
        $farmAdmin = User::factory()->create();
        $farmAdmin->assignRole('operator');

        // Create a regular user who is not the creator or farm admin
        /** @var User $regularUser */
        $regularUser = User::factory()->create();
        $regularUser->assignRole('viewer');

        // Create another farm and its admin to test cross-farm access
        $otherFarm = Farm::factory()->create();
        /** @var User $otherFarmAdmin */
        $otherFarmAdmin = User::factory()->create();
        $otherFarmAdmin->assignRole('operator');

        // Attach users to farms with appropriate roles
        $creator->farms()->attach($this->farm->id, ['role' => 'operator', 'is_owner' => false]);
        $farmAdmin->farms()->attach($this->farm->id, ['role' => 'admin', 'is_owner' => false]);
        $regularUser->farms()->attach($this->farm->id, ['role' => 'viewer', 'is_owner' => false]);
        $otherFarmAdmin->farms()->attach($otherFarm->id, ['role' => 'admin', 'is_owner' => true]);

        // Create a farm report by the creator
        $report = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'reportable_type' => 'App\\Models\\Field',
            'reportable_id' => $this->field->id,
            'created_by' => $creator->id,
            'description' => 'Original description',
            'value' => 10.0,
        ]);

        $updateData = [
            'date' => '1404/01/12',
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'description' => 'Updated description',
            'value' => 20.0,
            'reportables' => [
                ['type' => 'field', 'id' => $this->field->id]
            ]
        ];

        // Test 1: Creator can update their own report
        $this->actingAs($creator);
        $response = $this->putJson("/api/farm_reports/{$report->id}", $updateData);
        $response->assertOk();

        // Verify the update was successful
        $this->assertDatabaseHas('farm_reports', [
            'id' => $report->id,
            'description' => 'Updated description',
            'value' => 20.0,
        ]);

        // Test 2: Farm admin can update the report
        $this->actingAs($farmAdmin);
        $updateData['description'] = 'Updated by farm admin';
        $updateData['value'] = 30.0;
        $response = $this->putJson("/api/farm_reports/{$report->id}", $updateData);
        $response->assertOk();

        // Verify the update was successful
        $this->assertDatabaseHas('farm_reports', [
            'id' => $report->id,
            'description' => 'Updated by farm admin',
            'value' => 30.0,
        ]);

        // Test 3: Regular user (not creator, not farm admin) cannot update
        $this->actingAs($regularUser);
        $updateData['description'] = 'Should not be updated';
        $response = $this->putJson("/api/farm_reports/{$report->id}", $updateData);
        $response->assertForbidden();

        // Verify the update was NOT successful
        $this->assertDatabaseMissing('farm_reports', [
            'id' => $report->id,
            'description' => 'Should not be updated',
        ]);

        // Test 4: Admin of another farm cannot update
        $this->actingAs($otherFarmAdmin);
        $updateData['description'] = 'Should not be updated by other farm admin';
        $response = $this->putJson("/api/farm_reports/{$report->id}", $updateData);
        $response->assertForbidden();

        // Verify the update was NOT successful
        $this->assertDatabaseMissing('farm_reports', [
            'id' => $report->id,
            'description' => 'Should not be updated by other farm admin',
        ]);

        // Test 5: System admin can update (current test user is admin)
        $this->actingAs($this->user); // This user has admin role from setUp
        $updateData['description'] = 'Updated by system admin';
        $updateData['value'] = 40.0;
        $response = $this->putJson("/api/farm_reports/{$report->id}", $updateData);
        $response->assertOk();

        // Verify the update was successful
        $this->assertDatabaseHas('farm_reports', [
            'id' => $report->id,
            'description' => 'Updated by system admin',
            'value' => 40.0,
        ]);
    }

    #[Test]
    public function it_can_filter_farm_reports_by_date_range()
    {
        // Create reports with different dates, with a small delay between each
        // to ensure proper ordering since we use latest()
        $report1 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'created_by' => $this->user->id,
            'date' => '2023-01-01'  // 1401/10/11 in Jalali
        ]);

        usleep(1000); // 1ms delay

        $report2 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'created_by' => $this->user->id,
            'date' => '2023-02-01'  // 1401/11/12 in Jalali
        ]);

        usleep(1000); // 1ms delay

        $report3 = FarmReport::factory()->create([
            'farm_id' => $this->farm->id,
            'operation_id' => $this->operation->id,
            'labour_id' => $this->labour->id,
            'created_by' => $this->user->id,
            'date' => '2023-03-01'  // 1401/12/10 in Jalali
        ]);

        // Test filtering with date range that includes reports 1 and 2
        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'date_range' => [
                    'from' => '1401/10/01',  // Before 2023-01-01
                    'to' => '1401/11/30'     // After 2023-02-01
                ]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertEquals([$report2->id, $report1->id], array_column($data, 'id'),
            'Records should be ordered by creation time (newest first)');

        // Test filtering with date range that includes only report 3
        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'date_range' => [
                    'from' => '1401/12/01',  // Before 2023-03-01
                    'to' => '1401/12/29'     // After 2023-03-01
                ]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $report3->id);

        // Test filtering with no results (future date range)
        $response = $this->postJson("/api/farms/{$this->farm->id}/farm_reports/filter", [
            'filters' => [
                'date_range' => [
                    'from' => '1402/01/01',  // Future date
                    'to' => '1402/01/30'     // Future date
                ]
            ]
        ]);

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
