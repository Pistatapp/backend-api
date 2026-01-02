<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Labour;
use App\Models\User;

class CreateUsersForExistingLaboursSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all labours that don't have a user_id
        $labours = Labour::whereNull('user_id')->get();

        if ($labours->isEmpty()) {
            $this->command->info('No labours without user accounts found.');
            return;
        }

        $this->command->info("Found {$labours->count()} labours without user accounts. Creating user accounts...");

        $created = 0;
        $skipped = 0;

        foreach ($labours as $labour) {
            try {
                // Check if user with this mobile already exists
                $user = User::where('mobile', $labour->mobile)->first();

                if (!$user) {
                    // Generate username from mobile (remove country code if exists)
                    $mobile = preg_replace('/^\+?98|^0/', '', $labour->mobile);
                    $baseUsername = 'labour_' . $mobile;
                    $username = $baseUsername;
                    $counter = 1;

                    // Ensure username is unique
                    while (User::where('username', $username)->exists()) {
                        $username = $baseUsername . '_' . $counter;
                        $counter++;
                    }

                    // Create user (use system user or first admin as creator, or null)
                    $creator = User::whereHas('roles', function ($query) {
                        $query->whereIn('name', ['root', 'super-admin', 'admin']);
                    })->first();

                    $user = User::create([
                        'mobile' => $labour->mobile,
                        'username' => $username,
                        'created_by' => $creator?->id,
                    ]);

                    // Create profile with name if it doesn't exist
                    if (!$user->profile) {
                        $nameParts = $this->splitName($labour->name);
                        $user->profile()->create([
                            'first_name' => $nameParts['first_name'],
                            'last_name' => $nameParts['last_name'],
                        ]);
                    }

                    $created++;
                    $this->command->info("Created user account for labour ID {$labour->id} ({$labour->name})");
                } else {
                    $this->command->info("User with mobile {$labour->mobile} already exists. Syncing role and linking to labour ID {$labour->id}.");
                }

                // Assign role based on work_type (sync roles to ensure only one role)
                $role = $labour->work_type === 'administrative' ? 'employee' : 'labour';
                $user->syncRoles($role);

                // Link user to labour
                $labour->update(['user_id' => $user->id]);

            } catch (\Exception $e) {
                $skipped++;
                $this->command->error("Failed to process labour ID {$labour->id}: {$e->getMessage()}");
            }
        }

        $this->command->info("Completed! Created: {$created}, Skipped: {$skipped}");
    }

    /**
     * Split a full name into first name and last name
     *
     * @param string $fullName
     * @return array
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }
}
