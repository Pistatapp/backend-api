<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'employees';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'farm_id',
        'fname',
        'lname',
        'national_id',
        'mobile',
        'work_type',
        'work_days',
        'work_hours',
        'start_work_time',
        'end_work_time',
        'monthly_salary',
        'hourly_wage',
        'overtime_hourly_wage',
        'user_id',
        'is_working',
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
     * Scope a query to only include working employees.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWorking($query)
    {
        return $query->where('is_working', true);
    }

    /**
     * Get full name of the Employee
     *
     * @return string
     */
    public function getFullNameAttribute()
    {
        return "{$this->fname} {$this->lname}";
    }

    /**
     * Get the teams that the Employee belongs to
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'employee_team');
    }

    /**
     * Get the farm that owns the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    /**
     * Get the user associated with the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the GPS data for the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function gpsData()
    {
        return $this->hasMany(WorkerGpsData::class, 'employee_id');
    }

    /**
     * Get the attendance sessions for the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendanceSessions()
    {
        return $this->hasMany(WorkerAttendanceSession::class, 'employee_id');
    }

    /**
     * Get the daily reports for the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function dailyReports()
    {
        return $this->hasMany(WorkerDailyReport::class, 'employee_id');
    }

    /**
     * Get the monthly payrolls for the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function monthlyPayrolls()
    {
        return $this->hasMany(WorkerMonthlyPayroll::class, 'employee_id');
    }

    /**
     * Get the shift schedules for the Employee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function shiftSchedules()
    {
        return $this->hasMany(WorkerShiftSchedule::class, 'employee_id');
    }
}
