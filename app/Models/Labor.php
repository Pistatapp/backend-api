<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Labor extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'type',
        'fname',
        'lname',
        'national_id',
        'mobile',
        'position',
        'project_start_date',
        'project_end_date',
        'work_type',
        'work_days',
        'work_hours',
        'start_work_time',
        'end_work_time',
        'salary',
        'daily_salary',
        'monthly_salary',
    ];

    /**
     * Get the team that owns the Labor
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
