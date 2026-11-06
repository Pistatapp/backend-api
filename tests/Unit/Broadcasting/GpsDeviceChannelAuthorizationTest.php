<?php

namespace Tests\Unit\Broadcasting;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Permission;
use App\Models\Tractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

class GpsDeviceChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_member_with_tractor_view_permission_is_allowed(): void
    {
        [$user, , $device] = $this->userFarmDevice('view-member');

        $this->assertTrue(($this->channelAuthorizer())($user, $device));
    }

    public function test_member_of_another_farm_is_denied(): void
    {
        [$user] = $this->userFarmDevice('farm-a');
        [, , $deviceOnFarmB] = $this->userFarmDevice('farm-b');

        $this->assertFalse(($this->channelAuthorizer())($user, $deviceOnFarmB));
    }

    public function test_member_of_the_device_farm_is_allowed(): void
    {
        [$user, , $device] = $this->userFarmDevice('farm-b-member');

        $this->assertTrue(($this->channelAuthorizer())($user, $device));
    }

    public function test_unassigned_device_is_denied(): void
    {
        [$user] = $this->userFarmDevice('unassigned-owner');
        $device = GpsDevice::factory()->create(['tractor_id' => null]);

        $this->assertFalse(($this->channelAuthorizer())($user, $device));
    }

    public function test_removed_farm_membership_is_denied_on_reauthorization(): void
    {
        [$user, $farm, $device] = $this->userFarmDevice('revocation');
        $authorizer = $this->channelAuthorizer();

        $this->assertTrue($authorizer($user, $device));

        $user->farms()->detach($farm->id);

        $this->assertFalse($authorizer($user->fresh(), $device->fresh()));
    }

    public function test_root_can_view_an_assigned_device(): void
    {
        $root = User::factory()->create();
        $root->assignRole('root');
        $device = GpsDevice::factory()->create([
            'tractor_id' => Tractor::factory()->create()->id,
        ]);

        $this->assertTrue(($this->channelAuthorizer())($root, $device));
    }

    public function test_broadcast_auth_requires_an_authenticated_user(): void
    {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-gps_devices.1',
        ])->assertUnauthorized();
    }

    private function channelAuthorizer(): \Closure
    {
        $authorizer = Broadcast::getChannels()->get('gps_devices.{gps_device}');

        $this->assertInstanceOf(\Closure::class, $authorizer);

        return $authorizer;
    }

    /**
     * @return array{0: User, 1: Farm, 2: GpsDevice}
     */
    private function userFarmDevice(string $suffix): array
    {
        $permission = Permission::findOrCreate('view-tractors-and-details', 'web');
        $user = User::factory()->create(['username' => $suffix.'-'.uniqid()]);
        $user->givePermissionTo($permission);

        $farm = Farm::factory()->create(['name' => $suffix]);
        $user->farms()->attach($farm->id, ['role' => 'viewer', 'is_owner' => false]);

        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);
        $device = GpsDevice::factory()->create(['tractor_id' => $tractor->id]);

        return [$user, $farm, $device];
    }
}
