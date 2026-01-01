<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabourShiftSchedule extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'labour_id',
        'shift_id',
        'scheduled_date',
        'status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
        ];
    }

    /**
     * Get the labour for the shift schedule
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labour()
    {
        return $this->belongsTo(Labour::class, 'labour_id');
    }

    /**
     * Get the work shift for the schedule
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function shift()
    {
        return $this->belongsTo(WorkShift::class, 'shift_id');
    }
}

