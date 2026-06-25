<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class StoreGpsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public array $data,
        public int $tractorId,
        public string $deviceImei,
    ) {
        $this->onQueue('gps-processing');
    }

    public function handle(): void
    {
        $records = $this->prepareBatch($this->data, $this->tractorId);

        DB::connection('mysql_gps')->transaction(function () use ($records) {
            foreach (array_chunk($records, 1000) as $chunk) {
                DB::table('gps_data')->insert($chunk);
            }
        });

        $this->writeRawGpsData();

        BroadcastGpsEvents::dispatch($this->data, $this->tractorId, $this->deviceImei);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function prepareBatch(array $data, int $tractorId): array
    {
        return array_map(function (array $item) use ($tractorId) {
            return array_merge($item, [
                'tractor_id' => $tractorId,
                'coordinate' => json_encode($item['coordinate']),
                'speed' => $item['speed'],
                'status' => $item['status'],
                'directions' => json_encode($item['directions']),
                'imei' => $item['imei'],
                'date_time' => $item['date_time'],
            ]);
        }, $data);
    }

    private function writeRawGpsData(): void
    {
        $linesByFile = [];

        foreach ($this->data as $item) {
            $date = substr($item['date_time'], 0, 10);
            $path = storage_path("logs/gps-raw/{$item['imei']}/{$date}.txt");
            $linesByFile[$path][] = '['.now()->toIso8601String().'] '.json_encode([$item]);
        }

        foreach ($linesByFile as $path => $lines) {
            File::ensureDirectoryExists(dirname($path));
            File::append($path, implode(PHP_EOL, $lines).PHP_EOL);
        }
    }
}
