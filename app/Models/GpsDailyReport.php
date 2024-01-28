<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GpsDailyReport extends Model
{
    use HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected $attributes = [
        'travel_distance' => 0,
        'work_duration' => 0,
        'stoppage_count' => 0,
        'stoppage_duration' => 0,
        'average_speed' => 0,
        'max_speed' => 0,
        'efficiency' => 0,
    ];
    
    public function trucktor()
    {
        return $this->belongsTo(Trucktor::class);
    }
}
