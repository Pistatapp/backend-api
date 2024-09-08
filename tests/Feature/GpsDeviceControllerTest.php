<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class GpsDeviceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_gps_device_routes()
    {
        $this->getJson(route('gps-devices.index'))->assertUnauthorized();
        $this->postJson(route('gps-devices.store'))->assertUnauthorized();
        $this->putJson(route('gps-devices.update', 1))->assertUnauthorized();
    }

    public function test_admins_can_access_gps_device_routes()
    {
        $this->actingAsAdmin();

        $this->getJson(route('gps-devices.index'))->assertOk();
        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();
        $this->putJson(route('gps-devices.update', 1), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertOk();
    }

    public function test_users_cannot_access_gps_device_routes()
    {
        $this->actingAsUser();

        $this->getJson(route('gps-devices.index'))->assertForbidden();
        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertForbidden();
        $this->putJson(route('gps-devices.update', 1), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertForbidden();
    }

    public function test_gps_device_index_returns_gps_devices()
    {
        $this->actingAsAdmin();

        $this->getJson(route('gps-devices.index'))->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'user',
                    'name',
                    'imei',
                    'sim_number',
                    'created_at',
                    'updated_at',
                ],
            ],
            'links',
            'meta',
        ]);
    }

    public function test_gps_device_store_creates_new_gps_device()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();
    }

    public function test_gps_device_update_updates_gps_device()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();

        $this->putJson(route('gps-devices.update', 1), [
            'user_id' => $this->user->id,
            'name' => 'Updated Device',
            'imei' => '543210987654321',
            'sim_number' => '543210987654321',
        ])->assertOk();
    }

    public function test_gps_device_store_returns_validation_error()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'))->assertJsonValidationErrors([
            'user_id',
            'name',
            'imei',
            'sim_number',
        ]);
    }

    public function test_gps_device_update_returns_validation_error()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();

        $this->putJson(route('gps-devices.update', 1))->assertJsonValidationErrors([
            'user_id',
            'name',
            'imei',
            'sim_number',
        ]);
    }

    public function test_gps_device_store_returns_unique_error()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertJsonValidationErrors([
            'imei',
            'sim_number',
        ]);
    }

    public function test_gps_device_update_returns_unique_error()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '543210987654321',
            'sim_number' => '543210987654321',
        ])->assertCreated();

        $this->putJson(route('gps-devices.update', 1), [
            'user_id' => $this->user->id,
            'name' => 'Updated Device',
            'imei' => '543210987654321',
            'sim_number' => '543210987654321',
        ])->assertJsonValidationErrors([
            'imei',
            'sim_number',
        ]);
    }

    public function test_gps_device_update_returns_not_found_error()
    {
        $this->actingAsAdmin();

        $this->putJson(route('gps-devices.update', 1))->assertNotFound();
    }

    public function test_gps_device_update_returns_forbidden_error()
    {
        $this->actingAsAdmin();

        $this->postJson(route('gps-devices.store'), [
            'user_id' => $this->user->id,
            'name' => 'Test Device',
            'imei' => '123456789012345',
            'sim_number' => '123456789012345',
        ])->assertCreated();

        $this->actingAsUser();

        $this->putJson(route('gps-devices.update', 1), [
            'user_id' => $this->user->id,
            'name' => 'Updated Device',
            'imei' => '543210987654321',
            'sim_number' => '543210987654321',
        ])->assertForbidden();
    }

    public function test_gps_device_update_returns_forbidden_error_for_guests()
    {
        $this->putJson(route('gps-devices.update', 1))->assertUnauthorized();
    }

    public function test_gps_device_store_returns_forbidden_error_for_guests()
    {
        $this->postJson(route('gps-devices.store'))->assertUnauthorized();
    }

    public function test_gps_device_index_returns_forbidden_error_for_guests()
    {
        $this->getJson(route('gps-devices.index'))->assertUnauthorized();
    }

    public function test_gps_device_store_returns_forbidden_error_for_users()
    {
        $this->actingAsUser();

        $this->postJson(route('gps-devices.store'))->assertForbidden();
    }
}
