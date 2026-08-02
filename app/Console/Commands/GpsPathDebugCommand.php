<?php

namespace App\Console\Commands;

use App\Models\Tractor;
use App\Services\TractorPathStreamService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ops diagnostic: compare raw gps_data rows vs path API yield for a tractor/day.
 */
class GpsPathDebugCommand extends Command
{
    protected $signature = 'gps:path-debug
                            {tractor : Tractor ID}
                            {--date= : Gregorian Y-m-d (default: today app TZ)}
                            {--no-correction : Disable Median/Kalman path correction}';

    protected $description = 'Compare raw gps_data count vs streamed path points for a tractor/day';

    public function handle(TractorPathStreamService $pathService): int
    {
        $tractorId = (int) $this->argument('tractor');
        $tractor = Tractor::find($tractorId);
        if (! $tractor) {
            $this->error("Tractor {$tractorId} not found");

            return self::FAILURE;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), config('app.timezone'))
            : Carbon::today(config('app.timezone'));

        $start = $date->copy()->startOfDay()->format('Y-m-d H:i:s');
        $end = $date->copy()->endOfDay()->format('Y-m-d H:i:s');

        try {
            $raw = DB::connection('mysql_gps')->selectOne('
                SELECT COUNT(*) AS c,
                       SUM(CASE WHEN speed > 0 THEN 1 ELSE 0 END) AS moving,
                       MIN(date_time) AS min_dt,
                       MAX(date_time) AS max_dt
                FROM gps_data
                WHERE tractor_id = ?
                  AND date_time >= ?
                  AND date_time <= ?
            ', [$tractorId, $start, $end]);
        } catch (Throwable $e) {
            $this->error('mysql_gps query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tractor {$tractorId} date={$date->toDateString()}");
        $this->line(sprintf(
            'Raw gps_data: count=%d speed>0=%d min=%s max=%s',
            (int) ($raw->c ?? 0),
            (int) ($raw->moving ?? 0),
            $raw->min_dt ?? 'null',
            $raw->max_dt ?? 'null'
        ));

        $response = $pathService->getTractorPath(
            $tractor,
            $date,
            ! $this->option('no-correction')
        );

        ob_start();
        $response->send();
        $content = ob_get_clean();
        $points = json_decode($content, true);
        $apiCount = is_array($points) ? count($points) : 0;

        $this->line("Path API points: {$apiCount}");

        if ((int) ($raw->c ?? 0) >= 2 && $apiCount < 2) {
            $this->error('BUG: DB has trail data but path API returns <2 points — Android cannot draw polyline');

            return self::FAILURE;
        }

        if ((int) ($raw->c ?? 0) === 0) {
            $this->warn('No rows in gps_data for this day — replay/ingest first; live WS does not prove DB writes');
        } elseif ($apiCount >= 2) {
            $this->info('OK: enough points for Android polyline (>=2)');
        }

        return self::SUCCESS;
    }
}
