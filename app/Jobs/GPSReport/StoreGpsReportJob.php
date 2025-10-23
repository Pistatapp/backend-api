<?php

namespace App\Jobs\GPSReport;

use App\Models\GpsReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StoreGpsReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Parsed GPS report data (one or many records)
     *
     * @var array
     */
    protected array $parsedData;

    /**
     * Create a new job instance.
     *
     * @param  array  $parsedData
     */
    public function __construct(array $parsedData)
    {
        $this->parsedData = $parsedData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Insert each parsed GPS record
            foreach ($this->parsedData as $record) {
                GpsReport::create($record);
            }
        } catch (\Throwable $e) {
            Log::error('StoreGpsReportsJob failed to insert GPS data', [
                'error' => $e->getMessage(),
                'data'  => $this->parsedData,
            ]);
        }
    }
}
