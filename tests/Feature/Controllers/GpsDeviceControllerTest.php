<?php

namespace Tests\Feature\Controllers;

use App\Models\GpsDevice;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GpsDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $rootUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Run role and permission seeder
        $this->seed(RolePermissionSeeder::class);

        // Create a regular user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // Create a root user
        $this->rootUser = User::factory()->create();
        $this->rootUser->assignRole('root');
    }

    #[Test]
    public function user_can_view_gps_devices_list(): void
    {
        GpsDevice::factory()->count(5)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/gps_devices');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'imei', 'sim_number']
                ],
                'links',
                'meta'
            ]);
    }

    #[Test]
    public function root_user_can_create_gps_device(): void
    {
        $deviceData = [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '9123456789',
        ];

        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/gps_devices', $deviceData);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'imei', 'sim_number']
            ]);

        $this->assertDatabaseHas('gps_devices', $deviceData);
    }

    #[Test]
    public function regular_user_cannot_create_gps_device(): void
    {
        $deviceData = [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '9123456789',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/gps_devices', $deviceData);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_update_gps_device(): void
    {
        $device = GpsDevice::factory()->create();

        $updateData = [
            'user_id' => $this->user->id,
            'name' => 'Updated Device',
            'imei' => '987654321098765',
            'sim_number' => '9876543210',
        ];

        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/gps_devices/{$device->id}", $updateData);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'imei', 'sim_number']
            ]);

        $this->assertDatabaseHas('gps_devices', $updateData);
    }

    #[Test]
    public function regular_user_cannot_update_gps_device(): void
    {
        $device = GpsDevice::factory()->create();

        $updateData = [
            'user_id' => $this->user->id,
            'name' => 'Updated Device',
            'imei' => '987654321098765',
            'sim_number' => '9876543210',
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/gps_devices/{$device->id}", $updateData);

        $response->assertForbidden();
    }

    #[Test]
    public function root_user_can_delete_gps_device(): void
    {
        $device = GpsDevice::factory()->create();

        $response = $this->actingAs($this->rootUser)
            ->deleteJson("/api/gps_devices/{$device->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('gps_devices', ['id' => $device->id]);
    }

    #[Test]
    public function regular_user_cannot_delete_gps_device(): void
    {
        $device = GpsDevice::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/gps_devices/{$device->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function validates_unique_imei_and_sim_number_on_create(): void
    {
        $existingDevice = GpsDevice::factory()->create();

        $deviceData = [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => $existingDevice->imei,
            'sim_number' => $existingDevice->sim_number,
        ];

        $response = $this->actingAs($this->rootUser)
            ->postJson('/api/gps_devices', $deviceData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['imei', 'sim_number']);
    }

    #[Test]
    public function root_user_can_view_specific_gps_device(): void
    {
        // Create a device to view
        $device = GpsDevice::factory()->create();

        $response = $this->actingAs($this->rootUser)
            ->getJson("/api/gps_devices/{$device->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'imei', 'sim_number', 'user', 'can']
            ])
            ->assertJson([
                'data' => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'imei' => $device->imei,
                    'sim_number' => $device->sim_number,
                    'user' => [
                        'id' => $device->user->id
                    ]
                ]
            ]);
    }

    #[Test]
    public function user_can_view_their_own_gps_device(): void
    {
        // Create a device owned by the user
        $device = GpsDevice::factory()->create([
            'user_id' => $this->user->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/gps_devices/{$device->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'imei', 'sim_number', 'user', 'can']
            ])
            ->assertJson([
                'data' => [
                    'user' => [
                        'id' => $this->user->id
                    ]
                ]
            ]);
    }

    #[Test]
    public function user_cannot_view_others_gps_device(): void
    {
        // Create a device owned by another user
        $device = GpsDevice::factory()->create([
            'user_id' => $this->rootUser->id
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/gps_devices/{$device->id}");

        $response->assertForbidden();
    }

    #[Test]
    public function validates_unique_imei_and_sim_number_on_update(): void
    {
        // Create two devices
        $device1 = GpsDevice::factory()->create();
        $device2 = GpsDevice::factory()->create();

        // Try to update device2 with device1's unique identifiers
        $updateData = [
            'user_id' => $this->user->id,
            'name' => 'Updated Device',
            'imei' => $device1->imei,
            'sim_number' => $device1->sim_number,
        ];

        $response = $this->actingAs($this->rootUser)
            ->putJson("/api/gps_devices/{$device2->id}", $updateData);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['imei', 'sim_number']);
    }

    #[Test]
    public function root_user_can_view_all_gps_devices(): void
    {
        // Create devices for different users
        GpsDevice::factory()->count(3)->create(['user_id' => $this->user->id]);
        GpsDevice::factory()->count(2)->create(['user_id' => $this->rootUser->id]);

        $response = $this->actingAs($this->rootUser)
            ->getJson('/api/gps_devices');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'imei', 'sim_number']
                ],
                'links',
                'meta'
            ]);

        $this->assertCount(5, $response->json('data'));
    }

    #[Test]
    public function non_root_user_can_only_view_their_gps_devices(): void
    {
        // Create devices for different users
        $userDevices = GpsDevice::factory()->count(3)->create(['user_id' => $this->user->id]);
        GpsDevice::factory()->count(2)->create(['user_id' => $this->rootUser->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/gps_devices');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'imei', 'sim_number']
                ],
                'links',
                'meta'
            ]);

        $this->assertCount(3, $response->json('data'));

        // Verify only user's devices are returned
        foreach ($response->json('data') as $device) {
            $this->assertEquals($this->user->id, $device['user']['id']);
        }
    }
}
