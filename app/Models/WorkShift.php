<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkShift extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'farm_id',
        'name',
        'start_time',
        'end_time',
        'work_hours',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'work_hours' => 'decimal:2',
        ];
    }

    /**
     * Get the farm that owns the WorkShift
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the shift schedules for the WorkShift
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function shiftSchedules()
    {
        return $this->hasMany(WorkerShiftSchedule::class, 'shift_id');
    }
}
