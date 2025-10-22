<?php namespace App\Jobs\GPSReport;

use App\Models\GpsReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StoreGpsReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The parsed GPS report data ready for insertion.
     *
     * @var array
     */
    protected array $parsedData;

    /**
     * Create a new job instance.
     *
     * @param  array  $parsedData  Parsed GPS report from GpsParseService
     */
    public function __construct(array $parsedData)
    {
        $this->parsedData = $parsedData;
    }

    /**
     * Execute the job and store the GPS report in the database.
     *
     * @return void
     */
    public function handle(): void
    {
        // Insert the parsed GPS record directly into the gps_reports table.
        GpsReport::create($this->parsedData);
    }
}
