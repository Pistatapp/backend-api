<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsData extends Model
{
    use HasFactory;

    /**
     * Disable Laravel's automatic timestamp management
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tractor_id',
        'coordinate',
        'speed',
        'status',
        'directions',
        'imei',
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
            'directions' => 'array',
            'date_time' => 'datetime',
            'speed' => 'integer',
            'status' => 'integer',
        ];
    }

    /**
     * Get the tractor that owns this GPS data.
     */
    public function tractor()
    {
        return $this->belongsTo(Tractor::class, 'tractor_id');
    }
}
