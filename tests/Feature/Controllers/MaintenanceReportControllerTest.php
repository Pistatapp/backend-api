<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Labour;
use App\Models\Maintenance;
use App\Models\MaintenanceReport;
use App\Models\Tractor;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MaintenanceReportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;
    private $user;
    private $farm;
    private $labour;
    private $maintenance;
    private $tractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::where('mobile', '09369238614')->first();
        $this->farm = $this->user->farms()->first();
        $this->labour = Labour::factory()->create(['farm_id' => $this->farm->id]);
        $this->maintenance = Maintenance::factory()->create(['farm_id' => $this->farm->id]);
        $this->tractor = Tractor::factory()->create(['farm_id' => $this->farm->id]);

        // Ensure the user's working environment matches the farm
        $this->user->update([
            'preferences->working_environment' => $this->farm->id,
        ]);

        $this->actingAs($this->user);
    }

    /** @test */
    public function it_can_list_maintenance_reports()
    {
        MaintenanceReport::factory()->count(3)->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
        ]);

        $response = $this->getJson('/api/maintenance_reports');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'maintenance' => ['id', 'name'],
                        'maintainable' => ['id', 'name'],
                        'maintained_by' => ['id', 'name'],
                        'date',
                        'description',
                        'created_at'
                    ]
                ]
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    /** @test */
    public function it_can_store_new_maintenance_report()
    {
        $data = [
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => 'tractor',
            'maintainable_id' => $this->tractor->id,
            'maintained_by' => $this->labour->id,
            'date' => '1404/01/01',
            'description' => 'Test maintenance report description'
        ];

        $response = $this->postJson('/api/maintenance_reports', $data);

        $response->assertCreated();

        $this->assertDatabaseHas('maintenance_reports', [
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'maintained_by' => $this->labour->id,
            'description' => 'Test maintenance report description'
        ]);
    }

    /** @test */
    public function it_validates_store_request()
    {
        $response = $this->postJson('/api/maintenance_reports', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'maintenance_id',
                'maintainable_type',
                'maintainable_id',
                'maintained_by',
                'date',
                'description'
            ]);
    }

    /** @test */
    public function it_can_show_maintenance_report()
    {
        $report = MaintenanceReport::factory()->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
        ]);

        $response = $this->getJson("/api/maintenance_reports/{$report->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'maintenance' => ['id', 'name'],
                    'maintainable' => ['id', 'name'],
                    'maintained_by' => ['id', 'name'],
                    'date',
                    'description',
                    'created_at'
                ]
            ]);
    }

    /** @test */
    public function it_can_update_maintenance_report()
    {
        $report = MaintenanceReport::factory()->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
        ]);

        $data = [
            'maintenance_id' => $this->maintenance->id,
            'maintained_by' => $this->labour->id,
            'date' => '1404/01/02',
            'description' => 'Updated maintenance report description'
        ];

        $response = $this->putJson("/api/maintenance_reports/{$report->id}", $data);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'maintenance' => ['id', 'name'],
                    'maintainable' => ['id', 'name'],
                    'maintained_by' => ['id', 'name'],
                    'date',
                    'description',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('maintenance_reports', [
            'id' => $report->id,
            'description' => 'Updated maintenance report description'
        ]);
    }

    /** @test */
    public function it_validates_update_request()
    {
        $report = MaintenanceReport::factory()->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
        ]);

        $response = $this->putJson("/api/maintenance_reports/{$report->id}", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'maintenance_id',
                'maintained_by',
                'date',
                'description'
            ]);
    }

    /** @test */
    public function it_can_delete_maintenance_report()
    {
        $report = MaintenanceReport::factory()->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
        ]);

        $response = $this->deleteJson("/api/maintenance_reports/{$report->id}");

        $response->assertStatus(410);
        $this->assertDatabaseMissing('maintenance_reports', ['id' => $report->id]);
    }

    /** @test */
    public function it_can_filter_maintenance_reports()
    {
        // Create reports with different dates
        $report1 = MaintenanceReport::factory()->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
            'date' => '2025-03-21' // 1404/01/01
        ]);

        $report2 = MaintenanceReport::factory()->create([
            'maintenance_id' => $this->maintenance->id,
            'maintainable_type' => get_class($this->tractor),
            'maintainable_id' => $this->tractor->id,
            'created_by' => $this->user->id,
            'maintained_by' => $this->labour->id,
            'date' => '2025-03-22' // 1404/01/02
        ]);

        $data = [
            'from' => '1404/01/01',
            'to' => '1404/01/02',
            'maintainable_type' => 'tractor',
            'maintainable_id' => $this->tractor->id
        ];

        $response = $this->postJson('/api/maintenance_reports/filter', $data);

        $response->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    /** @test */
    public function it_validates_filter_request()
    {
        $response = $this->postJson('/api/maintenance_reports/filter', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'from',
                'to',
                'maintainable_type',
                'maintainable_id'
            ]);
    }
}
