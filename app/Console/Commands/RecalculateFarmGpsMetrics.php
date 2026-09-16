<?php

namespace App\Console\Commands;

use App\Models\Farm;
use App\Models\GpsMetricsCalculation;
use App\Services\GpsDataAnalyzer;
use App\Services\TractorEfficiencyService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rebuild daily tractor summaries from the current GPS analysis rules.
 *
 * This command is deliberately additive/update-only: raw gps_data rows are
 * never deleted or rewritten, preserving zero-log-loss and auditability.
 */
class RecalculateFarmGpsMetrics extends Command
{
    protected $signature = 'gps:recalculate-farm-metrics
                            {farm : Farm ID}
                            {--from= : Inclusive Gregorian start date (Y-m-d)}
                            {--to= : Inclusive Gregorian end date (Y-m-d)}
                            {--force : Persist recalculated summaries; without it this is a dry run}';

    protected $description = 'Recalculate daily tractor GPS summaries without changing raw GPS logs';

    public function handle(
        GpsDataAnalyzer $gpsDataAnalyzer,
        TractorEfficiencyService $tractorEfficiencyService,
    ): int {
        $farm = Farm::find((int) $this->argument('farm'));
        if (! $farm) {
            $this->error('Farm not found.');

            return self::FAILURE;
        }

        try {
            [$from, $to] = $this->resolveDateRange();
        } catch (Throwable $e) {
            $this->error('Invalid date range: '.$e->getMessage());

            return self::FAILURE;
        }

        $tractors = $farm->tractors()->orderBy('id')->get();
        $tractorIds = $tractors->modelKeys();
        if ($tractorIds === []) {
            $this->info("Farm {$farm->id} has no tractors.");

            return self::SUCCESS;
        }

        $datesByTractor = DB::connection('mysql_gps')
            ->table('gps_data')
            ->whereIn('tractor_id', $tractorIds)
            ->where('date_time', '>=', $from->format('Y-m-d H:i:s'))
            ->where('date_time', '<', $to->copy()->addDay()->startOfDay()->format('Y-m-d H:i:s'))
            ->selectRaw('tractor_id, DATE(date_time) AS gps_date')
            ->groupBy('tractor_id', 'gps_date')
            ->orderBy('tractor_id')
            ->orderBy('gps_date')
            ->get()
            ->groupBy('tractor_id');

        $dryRun = ! (bool) $this->option('force');
        $this->info(sprintf(
            'Farm %s (%s), range %s..%s, mode=%s',
            $farm->id,
            $farm->name,
            $from->toDateString(),
            $to->toDateString(),
            $dryRun ? 'dry-run' : 'write',
        ));

        $processed = 0;
        $written = 0;

        foreach ($tractors as $tractor) {
            foreach ($datesByTractor->get($tractor->id, collect()) as $dateRow) {
                $date = Carbon::parse((string) $dateRow->gps_date, config('app.timezone', 'Asia/Tehran'));
                $results = $gpsDataAnalyzer->loadRecordsFor($tractor, $date)->analyze();
                $effectiveWorkDuration = max(0, (int) $results['movement_duration_seconds'])
                    + max(0, (int) $results['stoppage_duration_seconds']);

                $attributes = [
                    'tractor_id' => $tractor->id,
                    'date' => $date->toDateString(),
                    'tractor_task_id' => null,
                ];
                $values = [
                    'traveled_distance' => $results['movement_distance_km'],
                    'work_duration' => $results['movement_duration_seconds'],
                    'stoppage_count' => $results['stoppage_count'],
                    'stoppage_duration' => $results['stoppage_duration_seconds'],
                    'stoppage_duration_while_on' => $results['stoppage_duration_while_on_seconds'],
                    'stoppage_duration_while_off' => $results['stoppage_duration_while_off_seconds'],
                    'average_speed' => $results['average_speed'],
                    'efficiency' => $tractorEfficiencyService->calculate($tractor, $effectiveWorkDuration),
                    'timings' => [
                        'device_on_time' => $results['device_on_time'],
                        'first_movement_time' => $results['first_movement_time'],
                    ],
                ];

                $processed++;
                if (! $dryRun) {
                    GpsMetricsCalculation::updateOrCreate($attributes, $values);
                    $written++;
                }

                $this->line(sprintf(
                    'tractor=%d date=%s movement=%ss stoppage=%ss distance=%skm%s',
                    $tractor->id,
                    $date->toDateString(),
                    $values['work_duration'],
                    $values['stoppage_duration'],
                    $values['traveled_distance'],
                    $dryRun ? ' (not written)' : '',
                ));
            }
        }

        $this->info("Processed {$processed} daily summary(ies).".($dryRun ? '' : " Written {$written}."));
        $this->comment('Raw gps_data rows were not deleted or modified.');

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolveDateRange(): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $from = $this->option('from')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('from'), $timezone)->startOfDay()
            : Carbon::now($timezone)->subMonthNoOverflow()->startOfDay();
        $to = $this->option('to')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('to'), $timezone)->startOfDay()
            : Carbon::today($timezone);

        if ($from->greaterThan($to)) {
            throw new \InvalidArgumentException('The start date must not be after the end date.');
        }

        return [$from, $to];
    }
}
