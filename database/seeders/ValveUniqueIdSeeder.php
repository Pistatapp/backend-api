<?php

namespace Database\Seeders;

use App\Helpers\UniqueId;
use App\Models\Valve;
use Illuminate\Database\Seeder;

class ValveUniqueIdSeeder extends Seeder
{
    /**
     * Generate unique_id for existing valves that do not have one.
     */
    public function run(): void
    {
        Valve::query()
            ->whereNull('unique_id')
            ->orderBy('id')
            ->chunkById(200, function ($valves) {
                foreach ($valves as $valve) {
                    $valve->update(UniqueId::makeForTable('valves'));
                }
            });
    }
}
