<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Labour;

class WrapLabourWorkTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all labours with non-null work_type values
        $labours = Labour::whereNotNull('work_type')->get();

        if ($labours->isEmpty()) {
            $this->command->info('No labours with work_type found.');
            return;
        }

        $this->command->info("Found {$labours->count()} labours with work_type. Wrapping values in brackets...");

        $updated = 0;
        $skipped = 0;

        foreach ($labours as $labour) {
            try {
                $workType = $labour->work_type;

                // Skip if already wrapped in brackets
                if (preg_match('/^\[.*\]$/', $workType)) {
                    $skipped++;
                    continue;
                }

                // Wrap the work_type value in brackets
                $wrappedWorkType = '[' . $workType . ']';

                $labour->update(['work_type' => $wrappedWorkType]);
                $updated++;

                $this->command->info("Updated labour ID {$labour->id}: '{$workType}' -> '{$wrappedWorkType}'");

            } catch (\Exception $e) {
                $skipped++;
                $this->command->error("Failed to process labour ID {$labour->id}: {$e->getMessage()}");
            }
        }

        $this->command->info("Completed! Updated: {$updated}, Skipped: {$skipped}");
    }
}
