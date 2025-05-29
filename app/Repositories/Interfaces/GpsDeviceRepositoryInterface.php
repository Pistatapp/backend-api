<?php

namespace App\Repositories\Interfaces;

use App\Models\GpsDevice;
use Illuminate\Database\Eloquent\Collection;

interface GpsDeviceRepositoryInterface
{
    /**
     * Get all GPS devices
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * Find a GPS device by ID
     *
     * @param int $id
     * @return GpsDevice|null
     */
    public function findById(int $id): ?GpsDevice;

    /**
     * Find a GPS device by IMEI
     *
     * @param string $imei
     * @return GpsDevice|null
     */
    public function findByImei(string $imei): ?GpsDevice;

    /**
     * Get GPS devices for a specific user
     *
     * @param int $userId
     * @return Collection
     */
    public function getByUserId(int $userId): Collection;

    /**
     * Get GPS device with its relationships (user and tractor)
     *
     * @param int $id
     * @return GpsDevice|null
     */
    public function getWithRelations(int $id): ?GpsDevice;

    /**
     * Find GPS device by relations
     *
     * @param string $imei
     * @param array $relations
     * @return GpsDevice|null
     */
    public function findByRelations(string $imei, array $relations = []): ?GpsDevice;

    /**
     * Create a new GPS device
     *
     * @param array $data
     * @return GpsDevice
     */
    public function create(array $data): GpsDevice;

    /**
     * Update a GPS device
     *
     * @param int $id
     * @param array $data
     * @return GpsDevice|null
     */
    public function update(int $id, array $data): ?GpsDevice;

    /**
     * Delete a GPS device
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;
}
