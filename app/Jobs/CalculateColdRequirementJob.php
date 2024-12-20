<?php

namespace App\Jobs;

use App\Models\VolkOilSpray;
use App\Notifications\VolkOilSprayNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateColdRequirementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public VolkOilSpray $volkOilSpray
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        $data = weather_api()->history($this->volkOilSpray->farm->center, $this->volkOilSpray->start_dt, $this->volkOilSpray->end_dt);

        $coldRequirement = $this->calculateColdRequirementMethod1($data, $this->volkOilSpray->min_temp, $this->volkOilSpray->max_temp);

        if ($coldRequirement < $this->volkOilSpray->cold_requirement) {
            $this->volkOilSpray->user->notify(new VolkOilSprayNotification($this->volkOilSpray, $coldRequirement));
        }
    }

    /**
     * Calculate the cold requirement for the farm using method 1.
     *
     * @param array $data
     * @param int $minTemp
     * @param int $maxTemp
     * @return int
     */
    private function calculateColdRequirementMethod1(array $data, int $minTemp, int $maxTemp): int
    {
        return collect($data['forecast']['forecastday'])->sum(function ($day) use ($minTemp, $maxTemp) {
            return collect($day['hour'])->filter(function ($hour) use ($minTemp, $maxTemp) {
                $temp = $hour['temp_c'];
                return $temp >= $minTemp && $temp <= $maxTemp;
            })->count();
        });
    }
}
