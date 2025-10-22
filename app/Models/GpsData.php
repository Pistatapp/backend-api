<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsData extends Model
{
    use HasFactory;

    /**
     * Disable Laravel's automatic timestamp management
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'device_id',
        'coordinate',
        'speed',
        'status',
        'directions',
        'imei',
        'date_time',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordinate' => 'array',
            'directions' => 'array',
            'date_time' => 'datetime',
            'speed' => 'integer',
            'status' => 'integer',
        ];
    }

    /**
     * Get the GPS device that owns this GPS data.
     */
    public function device()
    {
        return $this->belongsTo(GpsDevice::class, 'device_id');
    }
}

