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
    protected $fillable = [
        'tractor_id',
        'tractor_task_id',
        'date',
        'traveled_distance',
        'work_duration',
        'stoppage_count',
        'stoppage_duration',
        'stoppage_duration_while_on',
        'stoppage_duration_while_off',
        'average_speed',
        'efficiency',
        'timings',
    ];

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
            'date' => 'date',
            'timings' => 'array',
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
        'stoppage_duration_while_on' => 0,
        'stoppage_duration_while_off' => 0,
        'average_speed' => 0,
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
