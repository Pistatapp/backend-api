<?php

namespace App\Console\Commands;

use App\Jobs\IngestGpsData;
use App\Models\GpsDevice;
use App\Models\Tractor;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Smoke-test that mysql_gps accepts a synthetic GPS row via IngestGpsData.
 */
class GpsPersistSmokeCommand extends Command
{
    protected $signature = 'gps:persist-smoke
                            {--tractor= : Tractor ID (default: first tractor with a GPS device)}
                            {--keep : Do not delete the synthetic row after the test}';

    protected $description = 'Insert one synthetic GPS row via IngestGpsData and verify it lands in mysql_gps';

    public function handle(): int
    {
        $tractorId = $this->option('tractor');
        $device = null;

        if ($tractorId) {
            $tractor = Tractor::with('gpsDevice')->find((int) $tractorId);
            $device = $tractor?->gpsDevice;
        } else {
            $device = GpsDevice::query()->whereNotNull('tractor_id')->where('imei', '!=', '')->first();
            $tractor = $device?->tractor;
        }

        if (! $device || ! $tractor) {
            $this->error('No tractor with bound GPS IMEI found.');

            return self::FAILURE;
        }

        $imei = (string) $device->imei;
        $stamp = Carbon::now()->format('Y-m-d H:i:s');
        $payload = [[
            'coordinate' => [35.6892, 51.3890],
            'speed' => 5,
            'status' => 1,
            'directions' => ['ew' => 1, 'ns' => 1],
            'date_time' => $stamp,
            'imei' => $imei,
        ]];

        $this->info("Running IngestGpsData for tractor={$tractor->id} imei={$imei} at {$stamp}");

        try {
            // Avoid enqueueing WS side-effects during smoke test.
            \Illuminate\Support\Facades\Queue::fake();
            (new IngestGpsData($payload, 'smoke-'.uniqid()))->handle();
        } catch (Throwable $e) {
            $this->error('IngestGpsData failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $row = DB::connection('mysql_gps')->table('gps_data')
                ->where('imei', $imei)
                ->where('tractor_id', $tractor->id)
                ->where('date_time', $stamp)
                ->first();
        } catch (Throwable $e) {
            $this->error('Verify query failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $row) {
            $this->error('FAIL: row not found in mysql_gps.gps_data after ingest.');

            return self::FAILURE;
        }

        $this->info('OK: row id='.$row->id.' stored in mysql_gps.');

        if (! $this->option('keep')) {
            DB::connection('mysql_gps')->table('gps_data')->where('id', $row->id)->where('date_time', $stamp)->delete();
            $this->line('Synthetic row deleted (use --keep to retain).');
        }

        return self::SUCCESS;
    }
}
