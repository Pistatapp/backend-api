<?php

namespace Database\Factories;

use App\Models\Maintenance;
use App\Models\User;
use App\Models\Labour;
use App\Models\Tractor;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaintenanceReportFactory extends Factory
{
    public function definition()
    {
        $tractor = Tractor::factory()->create();

        return [
            'maintenance_id' => Maintenance::factory(),
            'maintainable_type' => get_class($tractor),
            'maintainable_id' => $tractor->id,
            'date' => $this->faker->date(),
            'description' => $this->faker->paragraph(),
            'created_by' => User::factory(),
            'maintained_by' => Labour::factory(),
        ];
    }
}
