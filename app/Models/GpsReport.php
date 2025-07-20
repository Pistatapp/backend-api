<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsReport extends Model
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
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_stopped' => 'boolean',
            'is_starting_point' => 'boolean',
            'is_ending_point' => 'boolean',
            'date_time' => 'datetime',
            'coordinate' => 'array',
            'direction' => 'integer',
        ];
    }

    /**
     * The default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_stopped' => false,
        'is_starting_point' => false,
        'is_ending_point' => false,
        'stoppage_time' => 0,
    ];

    /**
     * Get the gpsDevice that owns the GpsReport
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gpsDevice()
    {
        return $this->belongsTo(GpsDevice::class);
    }

    /**
     * Increment the stoppage time by the given time difference.
     *
     * @param int $timeDiff The time difference to increment by.
     * @return void
     */
    public function incrementStoppageTime(int $timeDiff): void
    {
        $this->update([
            'stoppage_time' => $this->stoppage_time + $timeDiff,
        ]);
    }

}
