<?php

namespace Tests\Feature\Maintenance;

use App\Models\Farm;
use App\Models\Labour;
use App\Models\Maintenance;
use App\Models\Tractor;
use App\Models\User;
use Morilog\Jalali\Jalalian;

trait InteractsWithMaintenanceContext
{
    protected function jalaliToday(): string
    {
        return Jalalian::fromCarbon(now())->format('Y/m/d');
    }

    /**
     * @return array{0: User, 1: Farm}
     */
    protected function createUserWithWorkingFarm(): array
    {
        $farm = Farm::factory()->create();
        $user = User::factory()->create([
            'is_active' => true,
            'preferences' => ['working_environment' => $farm->id],
        ]);
        $user->farms()->attach($farm->id, ['role' => 'admin', 'is_owner' => false]);

        return [$user, $farm];
    }

    /**
     * @return array{maintenance: Maintenance, labour: Labour, tractor: Tractor}
     */
    protected function createMaintenanceEntities(Farm $farm): array
    {
        $maintenance = Maintenance::factory()->create(['farm_id' => $farm->id]);
        $labour = Labour::factory()->create(['farm_id' => $farm->id]);
        $tractor = Tractor::factory()->create(['farm_id' => $farm->id]);

        return [
            'maintenance' => $maintenance,
            'labour' => $labour,
            'tractor' => $tractor,
        ];
    }
}
