<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Farm;

class FixFarmOwnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Farm::with('user')->get()->map(function (Farm $farm) {
            $farm->users()->attach($farm->user, ['is_owner' => true, 'role' => 'admin']);
        });
    }
}
