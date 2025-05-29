<?php

namespace App\Repositories;

use App\Models\GpsDevice;
use App\Repositories\Interfaces\GpsDeviceRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class GpsDeviceRepository implements GpsDeviceRepositoryInterface
{
    /**
     * @var GpsDevice
     */
    protected $model;

    /**
     * GpsDeviceRepository constructor.
     *
     * @param GpsDevice $model
     */
    public function __construct(GpsDevice $model)
    {
        $this->model = $model;
    }

    /**
     * {@inheritDoc}
     */
    public function all(): Collection
    {
        return $this->model->all();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?GpsDevice
    {
        return $this->model->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function findByImei(string $imei): ?GpsDevice
    {
        return $this->model->where('imei', $imei)->first();
    }

    /**
     * {@inheritDoc}
     */
    public function getByUserId(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getWithRelations(int $id): ?GpsDevice
    {
        return $this->model->with(['user', 'tractor'])->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): GpsDevice
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int $id, array $data): ?GpsDevice
    {
        $gpsDevice = $this->findById($id);

        if (!$gpsDevice) {
            return null;
        }

        $gpsDevice->update($data);
        return $gpsDevice->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int $id): bool
    {
        $gpsDevice = $this->findById($id);

        if (!$gpsDevice) {
            return false;
        }

        return $gpsDevice->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function getByRelations(string $imei, array $relations = []): ?GpsDevice
    {
        $model = $this->model->where('imei', $imei);

        foreach ($relations as $relation) {
            $model = $model->whereHas($relation);
        }

        if (!empty($relations)) {
            $model = $model->with($relations);
        }


        return $model->first();
    }
}
