<?php

namespace App\Services;

use App\Models\Tractor;
use App\Models\GpsReport;
use App\Http\Resources\PointsResource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TractorPathService
{
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly KalmanFilter $kalmanFilter
    ) {}

    /**
     * Retrieves the tractor path for a specific date.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function getTractorPath(Tractor $tractor, Carbon $date)
    {
        $filteredReports = collect();
        $device = $tractor->gpsDevice;

        GpsReport::where('gps_device_id', $device->id)
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->chunk(self::CHUNK_SIZE, function ($chunk) use (&$filteredReports) {
                $filteredChunk = $chunk->map(fn($report) => $this->applyKalmanFilter($report));
                $filteredReports = $filteredReports->merge($filteredChunk);
            });

        return PointsResource::collection($filteredReports);
    }

    /**
     * Retrieves the tractor path for a specific date using streamed JSON response.
     * Uses generators and response()->streamJson for efficient, incremental delivery.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Symfony\Component\HttpFoundation\StreamedJsonResponse
     */
    public function getTractorPathStreamed(Tractor $tractor, Carbon $date)
    {
        $generator = $this->streamPointsGenerator($tractor, $date);

        return response()->streamJson([
            'data' => $generator,
        ], 200, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Create a generator that yields filtered and transformed GPS reports.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Generator
     */
    private function streamPointsGenerator(Tractor $tractor, Carbon $date): \Generator
    {
        $device = $tractor->gpsDevice;

        $cursor = GpsReport::where('gps_device_id', $device->id)
            ->whereDate('date_time', $date)
            ->orderBy('date_time')
            ->cursor();

        $count = 0;
        foreach ($cursor as $report) {
            $filteredReport = $this->applyKalmanFilter($report);
            $resource = new PointsResource($filteredReport);
            yield $resource->toArray(request());

            // Optionally flush in long streams to push data more aggressively
            if ((++$count % self::CHUNK_SIZE) === 0) {
                if (function_exists('flush')) {
                    @ob_flush();
                    @flush();
                }
            }
        }
    }

    /**
     * Apply Kalman filter to a GPS report.
     *
     * @param GpsReport $report
     * @return GpsReport
     */
    private function applyKalmanFilter(GpsReport $report): GpsReport
    {
        $filtered = $this->kalmanFilter->filter($report->coordinate[0], $report->coordinate[1]);
        $report->coordinate = [$filtered['latitude'], $filtered['longitude']];
        return $report;
    }


    /**
     * Get the chunk size used for processing GPS reports.
     *
     * @return int
     */
    public function getChunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    /**
     * Prepare streamed JSON response for tractor path data.
     *
     * @param Tractor $tractor
     * @param Carbon $date
     * @return \Illuminate\Http\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function prepareStreamedResponse(Tractor $tractor, Carbon $date)
    {
        try {
            return $this->getTractorPathStreamed($tractor, $date);
        } catch (\Exception $e) {
            Log::error("Failed to create streamed response", [
                'tractor_id' => $tractor->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Failed to create streamed response'
            ], 500);
        }
    }
}
