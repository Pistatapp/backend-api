<?php

namespace App\Services\Gps;

/**
 * Data Transfer Object for GPS Analysis State
 * Encapsulates all cacheable state for incremental processing
 */
class GpsAnalysisState
{
    public function __construct(
        // Last processed point info
        public int $lastProcessedTimestamp = 0,
        public int $lastProcessedIndex = 0,
        
        // Detected times (once found, never recompute)
        public ?string $deviceOnTime = null,
        public ?string $firstMovementTime = null,
        public bool $deviceOnTimeDetected = false,
        public bool $firstMovementTimeDetected = false,
        
        // Accumulated metrics
        public float $movementDistance = 0.0,
        public int $movementDuration = 0,
        public int $stoppageDuration = 0,
        public int $stoppageDurationWhileOn = 0,
        public int $stoppageDurationWhileOff = 0,
        public int $stoppageCount = 0,
        public int $maxSpeed = 0,
        
        // State machine
        public bool $isCurrentlyMoving = false,
        public bool $isCurrentlyStopped = false,
        public ?int $segmentStartIndex = null,
        public ?int $segmentStartTimestamp = null,
        public float $segmentDistance = 0.0,
        
        // For first_movement_time detection (3 consecutive movements)
        public int $consecutiveMovementCount = 0,
        public ?int $firstConsecutiveMovementTimestamp = null,
        
        // Last point data for continuity
        public ?float $lastLat = null,
        public ?float $lastLon = null,
        public ?float $lastLatRad = null,
        public ?float $lastLonRad = null,
        public ?int $lastSpeed = null,
        public ?int $lastStatus = null,
        
        // First point info (for start_time in results)
        public ?string $startTime = null,
        
        // Segment start point for details (stored separately for detail generation)
        public ?float $segmentStartLat = null,
        public ?float $segmentStartLon = null,
        public ?int $segmentStartStatus = null,
        
        // Detail indices
        public int $movementDetailIndex = 0,
        public int $stoppageDetailIndex = 0,
    ) {}

    /**
     * Create from array (for cache deserialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            lastProcessedTimestamp: $data['lastProcessedTimestamp'] ?? 0,
            lastProcessedIndex: $data['lastProcessedIndex'] ?? 0,
            deviceOnTime: $data['deviceOnTime'] ?? null,
            firstMovementTime: $data['firstMovementTime'] ?? null,
            deviceOnTimeDetected: $data['deviceOnTimeDetected'] ?? false,
            firstMovementTimeDetected: $data['firstMovementTimeDetected'] ?? false,
            movementDistance: $data['movementDistance'] ?? 0.0,
            movementDuration: $data['movementDuration'] ?? 0,
            stoppageDuration: $data['stoppageDuration'] ?? 0,
            stoppageDurationWhileOn: $data['stoppageDurationWhileOn'] ?? 0,
            stoppageDurationWhileOff: $data['stoppageDurationWhileOff'] ?? 0,
            stoppageCount: $data['stoppageCount'] ?? 0,
            maxSpeed: $data['maxSpeed'] ?? 0,
            isCurrentlyMoving: $data['isCurrentlyMoving'] ?? false,
            isCurrentlyStopped: $data['isCurrentlyStopped'] ?? false,
            segmentStartIndex: $data['segmentStartIndex'] ?? null,
            segmentStartTimestamp: $data['segmentStartTimestamp'] ?? null,
            segmentDistance: $data['segmentDistance'] ?? 0.0,
            consecutiveMovementCount: $data['consecutiveMovementCount'] ?? 0,
            firstConsecutiveMovementTimestamp: $data['firstConsecutiveMovementTimestamp'] ?? null,
            lastLat: $data['lastLat'] ?? null,
            lastLon: $data['lastLon'] ?? null,
            lastLatRad: $data['lastLatRad'] ?? null,
            lastLonRad: $data['lastLonRad'] ?? null,
            lastSpeed: $data['lastSpeed'] ?? null,
            lastStatus: $data['lastStatus'] ?? null,
            startTime: $data['startTime'] ?? null,
            segmentStartLat: $data['segmentStartLat'] ?? null,
            segmentStartLon: $data['segmentStartLon'] ?? null,
            segmentStartStatus: $data['segmentStartStatus'] ?? null,
            movementDetailIndex: $data['movementDetailIndex'] ?? 0,
            stoppageDetailIndex: $data['stoppageDetailIndex'] ?? 0,
        );
    }

    /**
     * Convert to array (for cache serialization)
     */
    public function toArray(): array
    {
        return [
            'lastProcessedTimestamp' => $this->lastProcessedTimestamp,
            'lastProcessedIndex' => $this->lastProcessedIndex,
            'deviceOnTime' => $this->deviceOnTime,
            'firstMovementTime' => $this->firstMovementTime,
            'deviceOnTimeDetected' => $this->deviceOnTimeDetected,
            'firstMovementTimeDetected' => $this->firstMovementTimeDetected,
            'movementDistance' => $this->movementDistance,
            'movementDuration' => $this->movementDuration,
            'stoppageDuration' => $this->stoppageDuration,
            'stoppageDurationWhileOn' => $this->stoppageDurationWhileOn,
            'stoppageDurationWhileOff' => $this->stoppageDurationWhileOff,
            'stoppageCount' => $this->stoppageCount,
            'maxSpeed' => $this->maxSpeed,
            'isCurrentlyMoving' => $this->isCurrentlyMoving,
            'isCurrentlyStopped' => $this->isCurrentlyStopped,
            'segmentStartIndex' => $this->segmentStartIndex,
            'segmentStartTimestamp' => $this->segmentStartTimestamp,
            'segmentDistance' => $this->segmentDistance,
            'consecutiveMovementCount' => $this->consecutiveMovementCount,
            'firstConsecutiveMovementTimestamp' => $this->firstConsecutiveMovementTimestamp,
            'lastLat' => $this->lastLat,
            'lastLon' => $this->lastLon,
            'lastLatRad' => $this->lastLatRad,
            'lastLonRad' => $this->lastLonRad,
            'lastSpeed' => $this->lastSpeed,
            'lastStatus' => $this->lastStatus,
            'startTime' => $this->startTime,
            'segmentStartLat' => $this->segmentStartLat,
            'segmentStartLon' => $this->segmentStartLon,
            'segmentStartStatus' => $this->segmentStartStatus,
            'movementDetailIndex' => $this->movementDetailIndex,
            'stoppageDetailIndex' => $this->stoppageDetailIndex,
        ];
    }

    /**
     * Check if this state has any processed data
     */
    public function hasData(): bool
    {
        return $this->lastProcessedTimestamp > 0;
    }

    /**
     * Reset state for a new analysis
     */
    public function reset(): void
    {
        $this->lastProcessedTimestamp = 0;
        $this->lastProcessedIndex = 0;
        $this->deviceOnTime = null;
        $this->firstMovementTime = null;
        $this->deviceOnTimeDetected = false;
        $this->firstMovementTimeDetected = false;
        $this->movementDistance = 0.0;
        $this->movementDuration = 0;
        $this->stoppageDuration = 0;
        $this->stoppageDurationWhileOn = 0;
        $this->stoppageDurationWhileOff = 0;
        $this->stoppageCount = 0;
        $this->maxSpeed = 0;
        $this->isCurrentlyMoving = false;
        $this->isCurrentlyStopped = false;
        $this->segmentStartIndex = null;
        $this->segmentStartTimestamp = null;
        $this->segmentDistance = 0.0;
        $this->consecutiveMovementCount = 0;
        $this->firstConsecutiveMovementTimestamp = null;
        $this->lastLat = null;
        $this->lastLon = null;
        $this->lastLatRad = null;
        $this->lastLonRad = null;
        $this->lastSpeed = null;
        $this->lastStatus = null;
        $this->startTime = null;
        $this->segmentStartLat = null;
        $this->segmentStartLon = null;
        $this->segmentStartStatus = null;
        $this->movementDetailIndex = 0;
        $this->stoppageDetailIndex = 0;
    }
}

