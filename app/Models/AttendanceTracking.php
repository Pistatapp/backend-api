<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceTracking extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'farm_id',
        'work_type',
        'work_days',
        'work_hours',
        'start_work_time',
        'end_work_time',
        'hourly_wage',
        'overtime_hourly_wage',
        'imei',
        'attendence_tracking_enabled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'work_hours' => 'decimal:2',
            'attendence_tracking_enabled' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the attendance tracking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the farm that owns the attendance tracking.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }
}
