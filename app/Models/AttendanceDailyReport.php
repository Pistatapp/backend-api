<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDailyReport extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'attendance_daily_reports';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'date',
        'scheduled_hours',
        'actual_work_hours',
        'overtime_hours',
        'time_outside_zone',
        'productivity_score',
        'status',
        'admin_added_hours',
        'admin_reduced_hours',
        'notes',
        'approved_by',
        'approved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'scheduled_hours' => 'decimal:2',
            'actual_work_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'productivity_score' => 'decimal:2',
            'admin_added_hours' => 'decimal:2',
            'admin_reduced_hours' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Get the user for the daily report
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who approved the report
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
