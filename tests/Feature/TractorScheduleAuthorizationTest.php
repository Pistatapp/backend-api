<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Permission;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TractorScheduleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Permission $defineTractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineTractor = Permission::findOrCreate('define-tractor', 'web');
    }

    #[Test]
    public function a_farm_admin_with_permission_can_update_tractor_schedule(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($this->defineTractor);
        $farm->users()->attach($user->id, ['is_owner' => true, 'role' => 'admin']);
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tractors/{$tractor->id}", [
            'name' => $tractor->name,
            'start_work_time' => '07:30',
            'end_work_time' => '16:30',
            'expected_daily_work_time' => 9,
            'expected_monthly_work_time' => 270,
            'expected_yearly_work_time' => 3285,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('tractors', [
            'id' => $tractor->id,
            'start_work_time' => '07:30',
            'end_work_time' => '16:30',
            'expected_daily_work_time' => 9,
        ]);
    }

    #[Test]
    public function a_farm_member_without_schedule_permission_is_denied(): void
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $farm->users()->attach($user->id, ['is_owner' => true, 'role' => 'admin']);
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id, 'start_work_time' => '08:00']);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tractors/{$tractor->id}", [
            'name' => $tractor->name,
            'start_work_time' => '07:30',
            'end_work_time' => '16:30',
            'expected_daily_work_time' => 9,
            'expected_monthly_work_time' => 270,
            'expected_yearly_work_time' => 3285,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('tractors', [
            'id' => $tractor->id,
            'start_work_time' => '08:00',
        ]);
    }

    #[Test]
    public function schedule_permission_does_not_cross_farm_boundary(): void
    {
        $userFarm = Farm::factory()->create();
        $otherFarm = Farm::factory()->create();
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($this->defineTractor);
        $userFarm->users()->attach($user->id, ['is_owner' => true, 'role' => 'admin']);
        $tractor = Tractor::factory()->create([
            'farm_id' => $otherFarm->id,
            'start_work_time' => '08:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/tractors/{$tractor->id}", [
            'name' => $tractor->name,
            'start_work_time' => '07:30',
            'end_work_time' => '16:30',
            'expected_daily_work_time' => 9,
            'expected_monthly_work_time' => 270,
            'expected_yearly_work_time' => 3285,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('tractors', [
            'id' => $tractor->id,
            'farm_id' => $otherFarm->id,
            'start_work_time' => '08:00',
        ]);
    }
}
