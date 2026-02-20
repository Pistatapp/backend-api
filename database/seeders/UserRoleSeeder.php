<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all users that don't have any roles
        $usersWithoutRoles = User::whereDoesntHave('roles')->get();

        if ($usersWithoutRoles->isEmpty()) {
            $this->command->info('All users already have roles assigned.');
            return;
        }

        $this->command->info("Found {$usersWithoutRoles->count()} user(s) without roles. Assigning 'labour' role...");

        foreach ($usersWithoutRoles as $user) {
            $user->assignRole('labour');

            $identifier = $user->username ?? $user->mobile;
            $this->command->info("Assigned 'labour' role to user ID: {$user->id} ({$identifier})");
        }

        $this->command->info("Successfully assigned 'labour' role to {$usersWithoutRoles->count()} user(s).");
    }
}
