<?php

namespace Tests\Feature\Maintenance;

use App\Models\MaintenanceReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceReportMaintenanceFieldsApiTest extends TestCase
{
    use InteractsWithMaintenanceContext;
    use RefreshDatabase;

    public function test_store_persists_repair_shop_times_and_next_maintenance_km(): void
    {
        [$user, $farm] = $this->createUserWithWorkingFarm();
        $entities = $this->createMaintenanceEntities($farm);

        $entered = Carbon::parse('2026-04-01 08:00:00');
        $exited = Carbon::parse('2026-04-03 16:30:00');

        $payload = [
            'maintenance_id' => $entities['maintenance']->id,
            'maintainable_type' => 'tractor',
            'maintainable_id' => $entities['tractor']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => $this->jalaliToday(),
            'description' => 'Oil change and inspection.',
            'repair_shop_entered_at' => $entered->toIso8601String(),
            'repair_shop_exited_at' => $exited->toIso8601String(),
            'next_maintenance_km' => 450.5,
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/maintenance_reports', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.next_maintenance_km', 450.5);

        $this->assertDatabaseHas('maintenance_reports', [
            'maintainable_id' => $entities['tractor']->id,
            'next_maintenance_km' => 450.5,
        ]);

        $report = MaintenanceReport::where('maintainable_id', $entities['tractor']->id)->first();
        $this->assertNotNull($report->repair_shop_entered_at);
        $this->assertNotNull($report->repair_shop_exited_at);
    }

    public function test_store_accepts_null_optional_maintenance_fields(): void
    {
        [$user, $farm] = $this->createUserWithWorkingFarm();
        $entities = $this->createMaintenanceEntities($farm);

        $payload = [
            'maintenance_id' => $entities['maintenance']->id,
            'maintainable_type' => 'tractor',
            'maintainable_id' => $entities['tractor']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => $this->jalaliToday(),
            'description' => 'Routine check.',
            'repair_shop_entered_at' => null,
            'repair_shop_exited_at' => null,
            'next_maintenance_km' => null,
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/maintenance_reports', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.next_maintenance_km', null)
            ->assertJsonPath('data.repair_shop_entered_at', null)
            ->assertJsonPath('data.repair_shop_exited_at', null);
    }

    public function test_store_rejects_negative_next_maintenance_km(): void
    {
        [$user, $farm] = $this->createUserWithWorkingFarm();
        $entities = $this->createMaintenanceEntities($farm);

        $payload = [
            'maintenance_id' => $entities['maintenance']->id,
            'maintainable_type' => 'tractor',
            'maintainable_id' => $entities['tractor']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => $this->jalaliToday(),
            'description' => 'Routine check.',
            'next_maintenance_km' => -10,
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/maintenance_reports', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['next_maintenance_km']);
    }

    public function test_update_can_set_and_clear_optional_fields(): void
    {
        [$user, $farm] = $this->createUserWithWorkingFarm();
        $entities = $this->createMaintenanceEntities($farm);

        $report = MaintenanceReport::factory()->create([
            'maintenance_id' => $entities['maintenance']->id,
            'created_by' => $user->id,
            'maintainable_type' => \App\Models\Tractor::class,
            'maintainable_id' => $entities['tractor']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => now()->subDay(),
            'description' => 'Initial',
            'next_maintenance_km' => 200,
            'repair_shop_entered_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        $putPayload = [
            'maintenance_id' => $entities['maintenance']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => $this->jalaliToday(),
            'description' => 'Updated description text here.',
            'repair_shop_entered_at' => null,
            'repair_shop_exited_at' => null,
            'next_maintenance_km' => 300,
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/maintenance_reports/{$report->id}", $putPayload);

        $response->assertOk()
            ->assertJsonPath('data.next_maintenance_km', 300)
            ->assertJsonPath('data.repair_shop_entered_at', null);

        $report->refresh();
        $this->assertNull($report->repair_shop_entered_at);
        $this->assertEquals(300, (float) $report->next_maintenance_km);
    }

    public function test_update_without_optional_keys_preserves_stored_values(): void
    {
        [$user, $farm] = $this->createUserWithWorkingFarm();
        $entities = $this->createMaintenanceEntities($farm);

        $report = MaintenanceReport::factory()->create([
            'maintenance_id' => $entities['maintenance']->id,
            'created_by' => $user->id,
            'maintainable_type' => \App\Models\Tractor::class,
            'maintainable_id' => $entities['tractor']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => now()->subDay(),
            'description' => 'Initial',
            'next_maintenance_km' => 222,
            'repair_shop_entered_at' => Carbon::parse('2026-02-01 09:00:00'),
        ]);

        $putPayload = [
            'maintenance_id' => $entities['maintenance']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => $this->jalaliToday(),
            'description' => 'Only required fields changed.',
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/maintenance_reports/{$report->id}", $putPayload);

        $response->assertOk()
            ->assertJsonPath('data.next_maintenance_km', 222);

        $report->refresh();
        $this->assertEquals(222, (float) $report->next_maintenance_km);
        $this->assertNotNull($report->repair_shop_entered_at);
    }

    public function test_index_includes_new_fields_in_resource(): void
    {
        [$user, $farm] = $this->createUserWithWorkingFarm();
        $entities = $this->createMaintenanceEntities($farm);

        MaintenanceReport::factory()->create([
            'maintenance_id' => $entities['maintenance']->id,
            'created_by' => $user->id,
            'maintainable_type' => \App\Models\Tractor::class,
            'maintainable_id' => $entities['tractor']->id,
            'maintained_by' => $entities['labour']->id,
            'date' => now(),
            'next_maintenance_km' => 99.5,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/maintenance_reports');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $this->assertArrayHasKey('next_maintenance_km', $rows[0] ?? []);
    }
}
