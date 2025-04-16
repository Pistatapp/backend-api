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

class TractorControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Farm $farm;
    private Tractor $tractor;
    private GpsDevice $gpsDevice;

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
    public function it_lists_available_devices_for_tractor(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/tractors/{$this->tractor->id}/devices");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'imei']
                ]
            ])
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function it_assigns_device_to_tractor(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$this->gpsDevice->id}");

        $response->assertNoContent();

        $this->assertDatabaseHas('gps_devices', [
            'id' => $this->gpsDevice->id,
            'tractor_id' => $this->tractor->id,
        ]);
    }

    #[Test]
    public function it_unassigns_device_from_tractor(): void
    {
        // First assign the device to the tractor
        $this->gpsDevice->tractor()->associate($this->tractor)->save();

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/unassign_device/{$this->gpsDevice->id}");

        $response->assertNoContent();

        $this->assertDatabaseHas('gps_devices', [
            'id' => $this->gpsDevice->id,
            'tractor_id' => null,
        ]);
    }

    #[Test]
    public function it_prevents_unauthorized_device_assignment(): void
    {
        // Create another user's device
        $otherUser = User::factory()->create();
        $otherDevice = GpsDevice::factory()->create([
            'user_id' => $otherUser->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$otherDevice->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('gps_devices', [
            'id' => $otherDevice->id,
            'tractor_id' => null,
        ]);
    }

    #[Test]
    public function it_prevents_assigning_device_to_tractor_already_having_device(): void
    {
        // First assign a device to the tractor
        $existingDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => $this->tractor->id,
        ]);

        // Try to assign another device
        $anotherDevice = GpsDevice::factory()->create([
            'user_id' => $this->user->id,
            'tractor_id' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tractors/{$this->tractor->id}/assign_device/{$anotherDevice->id}");

        $response->assertForbidden();

        // Verify the original device is still assigned and the new one isn't
        $this->assertDatabaseHas('gps_devices', [
            'id' => $existingDevice->id,
            'tractor_id' => $this->tractor->id,
        ]);

        $this->assertDatabaseHas('gps_devices', [
            'id' => $anotherDevice->id,
            'tractor_id' => null,
        ]);
    }
}
