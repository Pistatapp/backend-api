<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Database\Seeder;

class UserProfileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all users that don't have a profile
        $usersWithoutProfile = User::doesntHave('profile')->get();

        if ($usersWithoutProfile->isEmpty()) {
            $this->command->info('All users already have profiles.');
            return;
        }

        $this->command->info("Found {$usersWithoutProfile->count()} user(s) without profiles. Creating profiles...");

        foreach ($usersWithoutProfile as $user) {
            Profile::create([
                'user_id' => $user->id,
                'name' => $user->username,
                'province' => 'Tehran',
                'city' => 'Tehran',
                'company' => 'Company',
                'personnel_number' => '1234567890',
            ]);

            $this->command->info("Created profile for user ID: {$user->id} ({$user->username})");
        }

        $this->command->info("Successfully created {$usersWithoutProfile->count()} profile(s).");
    }
}
