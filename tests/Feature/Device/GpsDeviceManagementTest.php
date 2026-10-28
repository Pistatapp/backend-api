<?php

namespace Tests\Feature\Device;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GpsDeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $root;
    private User $owner;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $rootRole = Role::findOrCreate('root', 'web');
        $assignPermission = Permission::findOrCreate('assign-gps-device', 'web');
        $rootRole->givePermissionTo($assignPermission);

        $this->root = User::factory()->create();
        $this->root->assignRole($rootRole);

        $this->owner = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    public function test_root_create_persists_selected_owner_and_returns_it(): void
    {
        $response = $this->actingAs($this->root)->postJson('/api/gps_devices', [
            'user_id' => $this->owner->id,
            'name' => 'Selected owner device',
            'imei' => '863070044333077',
            'sim_number' => '09120000001',
        ]);

        $response->assertCreated()->assertJsonPath('data.user.id', $this->owner->id);
        $this->assertDatabaseHas('gps_devices', [
            'imei' => '863070044333077',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_root_owner_update_is_atomic_from_client_perspective_and_survives_get(): void
    {
        $device = GpsDevice::factory()->create(['user_id' => $this->owner->id]);

        $response = $this->actingAs($this->root)->putJson("/api/gps_devices/{$device->id}", [
            'user_id' => $this->otherUser->id,
            'name' => $device->name,
            'imei' => $device->imei,
            'sim_number' => $device->sim_number,
        ]);

        $response->assertOk()->assertJsonPath('data.user.id', $this->otherUser->id);
        $this->assertDatabaseHas('gps_devices', [
            'id' => $device->id,
            'user_id' => $this->otherUser->id,
        ]);

        $this->actingAs($this->root)
            ->getJson("/api/gps_devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->otherUser->id);
    }

    public function test_root_available_selector_ignores_owner_scope_but_excludes_assigned_devices(): void
    {
        $farm = Farm::factory()->create();
        $unassigned = GpsDevice::factory()->create([
            'user_id' => $this->owner->id,
            'imei' => '863070044333078',
            'tractor_id' => null,
        ]);
        $assigned = GpsDevice::factory()->create([
            'user_id' => $this->owner->id,
            'imei' => '863070044333079',
            'tractor_id' => Tractor::factory()->create(['farm_id' => $farm->id])->id,
        ]);

        $response = $this->actingAs($this->root)
            ->getJson("/api/farms/{$farm->id}/gps-devices/available");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($unassigned->id, $ids);
        $this->assertNotContains($assigned->id, $ids);
    }

    public function test_non_root_cannot_change_device_owner(): void
    {
        $device = GpsDevice::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->otherUser)
            ->putJson("/api/gps_devices/{$device->id}", [
                'user_id' => $this->owner->id,
                'name' => $device->name,
                'imei' => $device->imei,
                'sim_number' => $device->sim_number,
            ])
            ->assertForbidden();
    }
}
