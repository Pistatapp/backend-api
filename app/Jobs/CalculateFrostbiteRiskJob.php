<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Notifications\FrostbiteRiskNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateFrostbiteRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $farms = Farm::with('frostbitRisks')->get();

        foreach ($farms as $farm) {
            foreach ($farm->frostbitRisks as $risk) {
                if ($risk->notify) {
                    $this->processRisk($farm, $risk);
                }
            }
        }
    }

    /**
     * Process the frostbite risk.
     *
     * @param \App\Models\Farm $farm
     * @param \App\Models\FrostbitRisk $risk
     * @return void
     */
    private function processRisk($farm, $risk)
    {
        if ($risk->type === 'normal') {
            $this->processNormalRisk($farm);
        } elseif ($risk->type === 'radiational') {
            $this->processRadiationalRisk($farm);
        }
    }

    /**
     * Process the normal frostbite risk.
     *
     * @param \App\Models\Farm $farm
     * @return void
     */
    private function processNormalRisk($farm)
    {
        $data = weather_api()->forecast($farm->center, 7);
        $daysWithRisk = collect($data['forecast']['forecastday'])
            ->filter(function ($day) {
                return $day['day']['mintemp_c'] <= 0;
            })
            ->map(function ($day) {
                return [
                    'temperature' => number_format($day['day']['mintemp_c'], 2),
                    'day' => jdate($day['date'])->format('l'),
                    'date' => jdate($day['date'])->format('Y/m/d'),
                    'warning' => true,
                ];
            });

        if ($daysWithRisk->isNotEmpty()) {
            $farm->notify(new FrostbiteRiskNotification($daysWithRisk->toArray()));
        }
    }

    /**
     * Process the radiational frostbite risk.
     *
     * @param \App\Models\Farm $farm
     * @return void
     */
    private function processRadiationalRisk($farm)
    {
        $data = weather_api()->forecast($farm->center, 2);
        $day = $data['forecast']['forecastday'][1];
        $maxDewPoint = collect($day['hour'])->max('dewpoint_c');
        $avgTemp = $day['day']['avgtemp_c'];

        $temp1 = (0.18 * $avgTemp) + (0.083 * $maxDewPoint) - 2.33;
        $temp2 = (0.21 * $avgTemp) + 2.3;

        if ($temp1 <= 0 || $temp2 <= 0) {
            $farm->notify(new FrostbiteRiskNotification([
                'temperature' => number_format($avgTemp, 2),
                'day' => jdate($day['date'])->format('l'),
                'date' => jdate($day['date'])->format('Y/m/d'),
                'warning' => true,
            ]));
        }
    }
}
