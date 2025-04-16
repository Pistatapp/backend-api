<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TractorDeviceManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $rootUser;
    protected Farm $farm;
    protected Tractor $tractor;
    protected GpsDevice $gpsDevice;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the roles and permissions
        $this->seed(RolePermissionSeeder::class);

        // Create users with different roles
        $this->user = User::factory()->create();
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');

        $this->user->assignRole('admin');

        // Create farm and associate with user
        $this->farm = Farm::factory()->create();
        $this->farm->users()->attach($this->user, ['is_owner' => true, 'role' => 'admin']);

        // Create tractor belonging to the farm
        $this->tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Create a GPS device belonging to the user
        $this->gpsDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => null,
        ]);
    }

    #[Test]
    public function user_can_see_available_devices_for_assignment()
    {
        // Create multiple devices for the user
        GpsDevice::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/tractors/{$this->tractor->id}/devices");

        $response->assertOk();
        $response->assertJsonCount(4, 'data'); // 3 new + 1 from setup
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'imei']
            ]
        ]);
    }

    #[Test]
    public function devices_already_assigned_to_tractors_are_not_listed()
    {
        // Create an unassigned device
        $unassignedDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => null,
        ]);

        // Create an assigned device
        $assignedDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => Tractor::factory()->create(['farm_id' => $this->farm->id]),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/tractors/{$this->tractor->id}/devices");

        $response->assertOk();

        // Only unassigned devices should be in the response
        $deviceIds = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($unassignedDevice->id, $deviceIds);
        $this->assertContains($this->gpsDevice->id, $deviceIds);
        $this->assertNotContains($assignedDevice->id, $deviceIds);
    }

    #[Test]
    public function user_can_assign_owned_device_to_tractor()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$this->gpsDevice->id}");

        $response->assertNoContent();

        $this->gpsDevice->refresh();
        $this->assertEquals($this->tractor->id, $this->gpsDevice->tractor_id);
    }

    #[Test]
    public function user_cannot_assign_device_they_do_not_own()
    {
        // Create another user and their device
        $anotherUser = User::factory()->create();
        $anotherDevice = GpsDevice::factory()->create([
            'user_id' => $anotherUser->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$anotherDevice->id}");

        $response->assertForbidden();

        $anotherDevice->refresh();
        $this->assertNull($anotherDevice->tractor_id);
    }

    #[Test]
    public function admin_can_assign_any_device_to_tractor()
    {
        // Create another user's device
        $anotherUser = User::factory()->create();
        $anotherDevice = GpsDevice::factory()->create([
            'user_id' => $anotherUser->id,
            'tractor_id' => null,
        ]);

        // Admin should be able to assign it
        $response = $this->actingAs($this->rootUser)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$anotherDevice->id}");

        $response->assertNoContent();

        $anotherDevice->refresh();
        $this->assertEquals($this->tractor->id, $anotherDevice->tractor_id);
    }

    #[Test]
    public function user_can_unassign_device_from_their_tractor()
    {
        // First assign the device
        $this->gpsDevice->tractor()->associate($this->tractor)->save();

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/unassign_device/{$this->gpsDevice->id}");

        $response->assertNoContent();

        $this->gpsDevice->refresh();
        $this->assertNull($this->gpsDevice->tractor_id);
    }

    #[Test]
    public function user_cannot_unassign_device_from_another_users_tractor()
    {
        // Create another user and tractor
        $anotherUser = User::factory()->create();
        $anotherFarm = Farm::factory()->create();
        $anotherFarm->users()->attach($anotherUser, ['is_owner' => true, 'role' => 'owner']);

        $anotherTractor = Tractor::factory()->create([
            'farm_id' => $anotherFarm->id,
        ]);

        // Assign this user's device to the other user's tractor
        $this->gpsDevice->tractor()->associate($anotherTractor)->save();

        // This user should not be able to unassign
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$anotherTractor->id}/unassign_device/{$this->gpsDevice->id}");

        $response->assertForbidden();

        $this->gpsDevice->refresh();
        $this->assertEquals($anotherTractor->id, $this->gpsDevice->tractor_id);
    }

    #[Test]
    public function cannot_assign_device_to_tractor_that_already_has_device()
    {
        // First assign a device to the tractor
        $existingDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
        ]);

        // Try to assign another device
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$this->gpsDevice->id}");

        $response->assertForbidden();

        // The existing device should still be assigned, and the new one should not
        $existingDevice->refresh();
        $this->gpsDevice->refresh();

        $this->assertEquals($this->tractor->id, $existingDevice->tractor_id);
        $this->assertNull($this->gpsDevice->tractor_id);
    }

    #[Test]
    public function unassigning_nonexistent_device_returns_not_found()
    {
        $nonexistentId = GpsDevice::max('id') + 1000;

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/unassign_device/{$nonexistentId}");

        $response->assertNotFound();
    }
}
