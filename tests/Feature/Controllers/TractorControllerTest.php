<?php

namespace Tests\Feature\Controllers;

use App\Models\Farm;
use App\Models\GpsDevice;
use App\Models\Tractor;
use App\Models\User;
use App\Models\Driver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TractorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Tractor $tractor;
    private GpsDevice $gpsDevice;
    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the roles and permissions
        $this->seed(RolePermissionSeeder::class);

        // Create a user, farm, tractor and GPS device
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->farm = Farm::factory()->create();

        // Associate user with farm (needed for authorization)
        $this->farm->users()->attach($this->user, ['is_owner' => true, 'role' => 'admin']);

        // Create tractor belonging to the farm
        $this->tractor = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
        ]);

        // Create GPS device belonging to the user but not assigned to any tractor
        $this->gpsDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => null,
        ]);

        // Create a driver belonging to the farm but not assigned to any tractor
        $this->driver = Driver::factory()->create([
            'farm_id' => $this->farm->id,
            'tractor_id' => null,
        ]);
    }

    #[Test]
    public function it_lists_tractors_for_a_farm(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/tractors");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'farm_id']
                ],
                'links',
                'meta'
            ]);
    }

    #[Test]
    public function it_shows_tractor_details(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/tractors/{$this->tractor->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'farm_id',
                    'start_work_time',
                    'end_work_time',
                    'expected_daily_work_time',
                    'expected_monthly_work_time',
                    'expected_yearly_work_time',
                    'created_at',
                    'can' => [
                        'add_driver',
                        'add_gps_device'
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_creates_a_tractor(): void
    {
        $tractorData = [
            'name' => 'New Test Tractor',
            'start_work_time' => '08:00',
            'end_work_time' => '17:00',
            'expected_daily_work_time' => 8,
            'expected_monthly_work_time' => 176,
            'expected_yearly_work_time' => 2112,
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/farms/{$this->farm->id}/tractors", $tractorData);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'New Test Tractor',
            ]);

        $this->assertDatabaseHas('tractors', [
            'farm_id' => $this->farm->id,
            'name' => 'New Test Tractor',
        ]);
    }

    #[Test]
    public function it_updates_a_tractor(): void
    {
        $updateData = [
            'name' => 'Updated Tractor Name',
            'start_work_time' => '07:30',
            'end_work_time' => '16:30',
            'expected_daily_work_time' => 9,
            'expected_monthly_work_time' => 180,
            'expected_yearly_work_time' => 2200,
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/tractors/{$this->tractor->id}", $updateData);

        $response->assertOk()
            ->assertJsonFragment([
            'name' => 'Updated Tractor Name',
            ]);

        $this->assertDatabaseHas('tractors', [
            'id' => $this->tractor->id,
            'name' => 'Updated Tractor Name',
        ]);
    }

    #[Test]
    public function it_deletes_a_tractor(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/tractors/{$this->tractor->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('tractors', [
            'id' => $this->tractor->id,
        ]);
    }

    #[Test]
    public function it_lists_available_devices_for_farm(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/gps-devices/available");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'imei']
                ]
            ])
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_assigns_driver_and_gps_device_to_tractor(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assignments", [
                'driver_id' => $this->driver->id,
                'gps_device_id' => $this->gpsDevice->id
            ]);

        $response->assertNoContent();

        $this->assertDatabaseHas('drivers', [
            'id' => $this->driver->id,
            'tractor_id' => $this->tractor->id
        ]);

        $this->assertDatabaseHas('gps_devices', [
            'id' => $this->gpsDevice->id,
            'tractor_id' => $this->tractor->id
        ]);
    }

    #[Test]
    public function it_replaces_existing_driver_and_gps_device_assignments(): void
    {
        // Create and assign initial driver and GPS device
        $initialDriver = Driver::factory()->create([
            'farm_id' => $this->farm->id,
            'tractor_id' => $this->tractor->id,
        ]);

        $initialGpsDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
        ]);

        // Assign new driver and GPS device
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assignments", [
                'driver_id' => $this->driver->id,
                'gps_device_id' => $this->gpsDevice->id
            ]);

        $response->assertNoContent();

        // Check that new assignments are set
        $this->assertDatabaseHas('drivers', [
            'id' => $this->driver->id,
            'tractor_id' => $this->tractor->id
        ]);

        $this->assertDatabaseHas('gps_devices', [
            'id' => $this->gpsDevice->id,
            'tractor_id' => $this->tractor->id
        ]);

        // Check that old assignments are removed
        $this->assertDatabaseHas('drivers', [
            'id' => $initialDriver->id,
            'tractor_id' => null
        ]);

        $this->assertDatabaseHas('gps_devices', [
            'id' => $initialGpsDevice->id,
            'tractor_id' => null
        ]);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assignments", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['driver_id', 'gps_device_id'])
            ->assertJson([
                'message' => 'The driver id field is required. (and 1 more error)',
                'errors' => [
                    'driver_id' => ['The driver id field is required.'],
                    'gps_device_id' => ['The gps device id field is required.']
                ]
            ]);
    }

    #[Test]
    public function it_validates_user_must_be_admin(): void
    {
        // Create a non-admin user and a GPS device owned by them
        $nonAdminUser = User::factory()->create();
        $nonAdminDevice = GpsDevice::factory()->create([
            'user_id' => $nonAdminUser->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($nonAdminUser)
            ->postJson("/api/tractors/{$this->tractor->id}/assignments", [
                'driver_id' => $this->driver->id,
                'gps_device_id' => $nonAdminDevice->id
            ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'User must be an admin.'
            ]);
    }

    #[Test]
    public function it_validates_user_must_own_gps_device(): void
    {
        // Create another user's GPS device
        $otherUser = User::factory()->create();
        $otherUserDevice = GpsDevice::factory()->create([
            'user_id' => $otherUser->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assignments", [
                'driver_id' => $this->driver->id,
                'gps_device_id' => $otherUserDevice->id
            ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'User must own the GPS device.'
            ]);
    }

    #[Test]
    public function it_validates_driver_must_belong_to_same_farm(): void
    {
        // Create a driver from another farm
        $otherFarm = Farm::factory()->create();
        $otherFarmDriver = Driver::factory()->create([
            'farm_id' => $otherFarm->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assignments", [
                'driver_id' => $otherFarmDriver->id,
                'gps_device_id' => $this->gpsDevice->id
            ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'Driver must belong to the same farm as the tractor.'
            ]);
    }

    #[Test]
    public function it_lists_available_tractors_for_farm(): void
    {
        // Assign a driver to existing tractor to avoid null driver resource
        $driverForTractor = Driver::factory()->create([
            'farm_id' => $this->farm->id,
            'tractor_id' => $this->tractor->id,
        ]);

        // Create a tractor that already has a gps device (should be excluded)
        $tractorWithDevice = Tractor::factory()->create([
            'farm_id' => $this->farm->id,
        ]);
        GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $tractorWithDevice->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/tractors/available");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'driver']
                ]
            ])
            ->assertJsonFragment([
                'id' => $this->tractor->id,
                'name' => $this->tractor->name,
            ])
            ->assertJsonCount(1, 'data');

        // Ensure driver details are present inside driver resource fragment
        $this->assertTrue(
            collect($response->json('data'))
                ->where('id', $this->tractor->id)
                ->first()['driver']['id'] === $driverForTractor->id
        );
    }

    #[Test]
    public function it_returns_empty_when_no_available_tractors_for_farm(): void
    {
        // Give existing tractor a gps device so none are available
        GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/farms/{$this->farm->id}/tractors/available");

        $response->assertOk()
            ->assertJson([
                'data' => []
            ]);
    }
}
