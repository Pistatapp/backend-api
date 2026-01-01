<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabourAttendanceSession extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'labour_id',
        'date',
        'entry_time',
        'exit_time',
        'total_in_zone_duration',
        'total_out_zone_duration',
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
            'date' => 'date',
            'entry_time' => 'datetime',
            'exit_time' => 'datetime',
        ];
    }

    /**
     * Get the labour for the attendance session
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labour()
    {
        return $this->belongsTo(Labour::class, 'labour_id');
    }
}

