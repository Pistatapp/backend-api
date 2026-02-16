<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceMonthlyPayroll extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'attendance_monthly_payrolls';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'month',
        'year',
        'total_work_hours',
        'total_required_hours',
        'total_overtime_hours',
        'base_wage_total',
        'overtime_wage_total',
        'additions',
        'deductions',
        'final_total',
        'generated_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_work_hours' => 'decimal:2',
            'total_required_hours' => 'decimal:2',
            'total_overtime_hours' => 'decimal:2',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Get the user for the monthly payroll
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
