<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabourGpsData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'labour_gps_data';

    /**
     * Disable Laravel's automatic timestamp management for created_at/updated_at
     * We only use date_time field
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'labour_id',
        'coordinate',
        'speed',
        'bearing',
        'accuracy',
        'provider',
        'date_time',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordinate' => 'array',
            'date_time' => 'datetime',
            'speed' => 'decimal:2',
            'bearing' => 'decimal:2',
            'accuracy' => 'decimal:2',
        ];
    }

    /**
     * Get the labour that owns this GPS data.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function labour()
    {
        return $this->belongsTo(Labour::class, 'labour_id');
    }
}

