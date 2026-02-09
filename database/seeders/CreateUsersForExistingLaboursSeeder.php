<?php

namespace Database\Seeders;

use App\Models\Labour;
use App\Models\User;
use Illuminate\Database\Seeder;

class CreateUsersForExistingLaboursSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * For each labour:
     * - If labour has no user: create User with Profile, link labour to user, attach user to farm with role labour.
     * - If labour has user: ensure farm_user pivot exists for the labour's farm with role labour, is_owner false.
     */
    public function run(): void
    {
        $labours = Labour::with('farm', 'user', 'user.profile')->get();

        if ($labours->isEmpty()) {
            $this->command->info('No labours found.');

            return;
        }

        $created = 0;
        $pivotCreated = 0;
        $skipped = 0;

        foreach ($labours as $labour) {
            try {
                $user = $this->resolveOrCreateUser($labour, $created);

                if (!$user) {
                    $skipped++;
                    $this->command->warn("Labour ID {$labour->id}: Skipped (no mobile or invalid data).");

                    continue;
                }

                $role = $labour->work_type === 'administrative' ? 'employee' : 'labour';

                // Ensure farm_user pivot exists for the labour's farm
                $farm = $labour->farm;
                if ($farm && !$user->farms()->where('farms.id', $farm->id)->exists()) {
                    $user->farms()->attach($farm->id, [
                        'role' => $role,
                        'is_owner' => false,
                    ]);
                    $pivotCreated++;
                }
            } catch (\Exception $e) {
                $skipped++;
                $this->command->error("Labour ID {$labour->id}: {$e->getMessage()}");
            }
        }

        $this->command->info("Completed. Users created: {$created}, Pivot records created: {$pivotCreated}, Skipped: {$skipped}");
    }

    /**
     * Get existing user for labour or create a new one.
     *
     * @return User|null
     */
    private function resolveOrCreateUser(Labour $labour, int &$created): ?User
    {
        // Labour has user_id and user exists
        if ($labour->user_id && $labour->user) {
            return $labour->user;
        }

        // Look up user by labour's mobile
        if ($labour->mobile) {
            $user = User::where('mobile', $labour->mobile)->first();
            if ($user) {
                return $user;
            }
        }

        // No user exists – create one (requires mobile)
        if (empty($labour->mobile)) {
            return null;
        }

        $user = User::create([
            'mobile' => $labour->mobile,
            'username' => $this->generateUniqueUsername($labour->mobile),
            'created_by' => null,
        ]);

        $user->assignRole('labour');

        $user->profile()->create([
            'name' => $labour->name ?? '',
        ]);

        $created++;
        $this->command->info("Created user ID {$user->id} for labour ID {$labour->id}.");

        return $user;
    }

    /**
     * Generate a unique username from mobile.
     */
    private function generateUniqueUsername(string $mobile): string
    {
        $sanitized = preg_replace('/^\+?98|^0/', '', $mobile);
        $base = 'labour_' . $sanitized;
        $username = $base;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . '_' . $counter;
            $counter++;
        }

        return $username;
    }
}
