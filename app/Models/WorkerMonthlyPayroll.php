<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkerMonthlyPayroll extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'employee_id',
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
     * Get the employee for the monthly payroll
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
