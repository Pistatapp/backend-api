<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsMetricsCalculation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * The timestamps are disabled.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts() {
        return [
            'last_activity' => 'datetime'
        ];
    }

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'traveled_distance' => 0,
        'work_duration' => 0,
        'stoppage_count' => 0,
        'stoppage_duration' => 0,
        'average_speed' => 0,
        'max_speed' => 0,
        'efficiency' => 0,
    ];

    /**
     * Get the tractor task associated with this metrics calculation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractorTask()
    {
        return $this->belongsTo(TractorTask::class);
    }

    /**
     * Get the tractor that owns the GpsMetricsCalculation
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class);
    }
}
