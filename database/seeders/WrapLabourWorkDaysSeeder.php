<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Labour;

class WrapLabourWorkDaysSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get all labours with non-null work_days values
        $labours = Labour::whereNotNull('work_days')->get();

        if ($labours->isEmpty()) {
            $this->command->info('No labours with work_days found.');
            return;
        }

        $this->command->info("Found {$labours->count()} labours with work_days. Wrapping values in brackets...");

        $updated = 0;
        $skipped = 0;

        foreach ($labours as $labour) {
            try {
                $workDays = $labour->work_days;

                // Skip if work_days is not an array or is empty
                if (!is_array($workDays) || empty($workDays)) {
                    $skipped++;
                    continue;
                }

                // Check if values are already wrapped in brackets
                $allWrapped = true;
                foreach ($workDays as $day) {
                    if (!preg_match('/^\[.*\]$/', (string)$day)) {
                        $allWrapped = false;
                        break;
                    }
                }

                if ($allWrapped) {
                    $skipped++;
                    continue;
                }

                // Wrap each work_days value in brackets
                $wrappedWorkDays = array_map(function ($day) {
                    $dayStr = (string)$day;
                    // Skip if already wrapped
                    if (preg_match('/^\[.*\]$/', $dayStr)) {
                        return $dayStr;
                    }
                    return '[' . $dayStr . ']';
                }, $workDays);

                $labour->update(['work_days' => $wrappedWorkDays]);
                $updated++;

                $this->command->info("Updated labour ID {$labour->id}: " . json_encode($workDays) . " -> " . json_encode($wrappedWorkDays));

            } catch (\Exception $e) {
                $skipped++;
                $this->command->error("Failed to process labour ID {$labour->id}: {$e->getMessage()}");
            }
        }

        $this->command->info("Completed! Updated: {$updated}, Skipped: {$skipped}");
    }
}
