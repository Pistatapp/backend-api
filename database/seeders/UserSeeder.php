<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = \App\Models\User::factory()
            ->count(3)
            ->has(\App\Models\Profile::factory())
            ->sequence(
                ['mobile' => '09107529334'],
                ['mobile' => '09369238614'],
                ['mobile' => '09195065248'],
            )
            ->create();

        $this->call([
            RolePermissionSeeder::class,
        ]);

        $users->where('mobile', '09107529334')->first()->assignRole('root');
        $users->where('mobile', '09195065248')->first()->assignRole('root');
        $users->where('mobile', '09369238614')->first()->assignRole('admin');
    }
}
