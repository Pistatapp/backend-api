<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Labour extends Model
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
        'personnel_number',
        'mobile',
        'work_type',
        'work_days',
        'work_hours',
        'start_work_time',
        'end_work_time',
        'hourly_wage',
        'overtime_hourly_wage',
        'attendence_tracking_enabled',
        'imei',
        'image',
        'is_working',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string|mixed>
     */
    protected function casts(): array
    {
        return [
            'is_working' => 'boolean',
            'work_days' => 'array',
            'attendence_tracking_enabled' => 'boolean',
        ];
    }

    /**
     * The attributes with default values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_working' => false,
    ];

    /**
     * Scope a query to only include working labours.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWorking($query)
    {
        return $query->where('is_working', true);
    }


    /**
     * Get the teams that the Labour belongs to
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'labour_team');
    }

    /**
     * Get the farm that owns the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user associated with the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }


    /**
     * Get the GPS data for the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsData()
    {
        return $this->hasMany(LabourGpsData::class, 'labour_id');
    }

    /**
     * Get the attendance sessions for the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendanceSessions()
    {
        return $this->hasMany(LabourAttendanceSession::class, 'labour_id');
    }

    /**
     * Get the daily reports for the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function dailyReports()
    {
        return $this->hasMany(LabourDailyReport::class, 'labour_id');
    }

    /**
     * Get the monthly payrolls for the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function monthlyPayrolls()
    {
        return $this->hasMany(LabourMonthlyPayroll::class, 'labour_id');
    }

    /**
     * Get the shift schedules for the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function shiftSchedules()
    {
        return $this->hasMany(LabourShiftSchedule::class, 'labour_id');
    }

    /**
     * Get the current shift schedule for the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function currentShiftSchedule()
    {
        return $this->hasOne(LabourShiftSchedule::class, 'labour_id')->whereDate('scheduled_date', '=', now()->toDateString());
    }

    /**
     * Get the GPS device assigned to the Labour
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function gpsDevice()
    {
        return $this->hasOne(GpsDevice::class, 'labour_id');
    }

    /**
     * Scope a query to only include labours with active devices.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithActiveDevice($query)
    {
        return $query->whereHas('gpsDevice', function ($q) {
            $q->where('is_active', true);
        });
    }

    /**
     * Get labour irrigation programs
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function irrigations()
    {
        return $this->hasMany(Irrigation::class, 'labour_id');
    }
}

